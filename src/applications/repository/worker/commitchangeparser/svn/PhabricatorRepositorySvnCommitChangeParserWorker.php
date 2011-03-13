<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class PhabricatorRepositorySvnCommitChangeParserWorker
  extends PhabricatorRepositoryCommitChangeParserWorker {

  protected function parseCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    // PREAMBLE: This class is absurdly complicated because it is very difficult
    // to get the information we need out of SVN. The actual data we need is:
    //
    //  1. Recursively, what were the affected paths?
    //  2. For each affected path, is it a file or a directory?
    //  3. How was each path affected (e.g. add, delete, move, copy)?
    //
    // We spend nearly all of our effort figuring out (1) and (2) because
    // "svn log" is not recursive and does not give us file/directory
    // information (that is, it will report a directory move as a single move,
    // even if many thousands of paths are affected).
    //
    // Instead, we have to "svn ls -R" the location of each path in its previous
    // life to figure out whether it is a file or a directory and exactly which
    // recursive paths were affected if it was moved or copied. This is very
    // complicated and has many special cases.

    $uri = $repository->getDetail('remote-uri');
    $svn_commit = $commit->getCommitIdentifier();

    $callsign = $repository->getCallsign();
    echo "Parsing r{$callsign}{$svn_commit}...\n";

    // Pull the top-level path changes out of "svn log". This is pretty
    // straightforward; just parse the XML log.
    list($xml) = execx(
      'svn log --verbose --xml --limit 1 --non-interactive %s@%d',
      $uri,
      $svn_commit);

    $log = new SimpleXMLElement($xml);
    $entry = $log->logentry[0];

    if (!$entry->paths) {
      // TODO: Explicitly mark this commit as broken elsewhere? This isn't
      // supposed to happen but we have some cases like rE27 and rG935 in the
      // Facebook repositories where things got all clowned up.
      return;
    }

    $raw_paths = array();
    foreach ($entry->paths->path as $path) {
      $name = trim((string)$path);
      $raw_paths[$name] = array(
        'rawPath'         => $name,
        'rawTargetPath'   => (string)$path['copyfrom-path'],
        'rawChangeType'   => (string)$path['action'],
        'rawTargetCommit' => (string)$path['copyfrom-rev'],
      );
    }

    $copied_or_moved_map = array();
    $deleted_paths = array();
    $add_paths = array();

    foreach ($raw_paths as $path => $raw_info) {
      if ($raw_info['rawTargetPath']) {
        $copied_or_moved_map[$raw_info['rawTargetPath']][] = $raw_info;
      }
      switch ($raw_info['rawChangeType']) {
        case 'D':
          $deleted_paths[$path] = $raw_info;
          break;
        case 'A':
          $add_paths[$path] = $raw_info;
          break;
      }
    }

    // If a path was deleted, we need to look in the repository history to
    // figure out where the former valid location for it is so we can figure out
    // if it was a directory or not, among other things.
    $lookup_here = array();
    foreach ($raw_paths as $path => $raw_info) {
      if ($raw_info['rawChangeType'] != 'D') {
        continue;
      }

      // If a change copies a directory and then deletes something from it,
      // we need to look at the old location for information about the path, not
      // the new location. This workflow is pretty ridiculous -- so much so that
      // Trac gets it wrong. See Facebook rO6 for an example, if you happen to
      // work at Facebook.
      $parents = $this->expandAllParentPaths($path, $include_self = true);
      foreach ($parents as $parent) {
        if (isset($add_paths[$parent])) {
          $relative_path = substr($path, strlen($parent));
          $lookup_here[$path] = array(
            'rawPath'   => $add_paths[$parent]['rawTargetPath'].$relative_path,
            'rawCommit' => $add_paths[$parent]['rawTargetCommit'],
          );
          continue 2;
        }
      }

      // Otherwise we can just look at the previous revision.
      $lookup_here[$path] = array(
        'rawPath'   => $path,
        'rawCommit' => $svn_commit - 1,
      );
    }

    $lookup = array();
    foreach ($raw_paths as $path => $raw_info) {
      if ($raw_info['rawChangeType'] == 'D') {
        $lookup[$path] = $lookup_here[$path];
      } else {
        // For everything that wasn't deleted, we can just look it up directly.
        $lookup[$path] = array(
          'rawPath'   => $path,
          'rawCommit' => $svn_commit,
        );
      }
    }

    $path_file_types = $this->lookupPathFileTypes($repository, $lookup);

    $effects = array();
    $resolved_types = array();
    $supplemental = array();
    foreach ($raw_paths as $path => $raw_info) {
      if (isset($resolved_types[$path])) {
        $type = $resolved_types[$path];
      } else {
        switch ($raw_info['rawChangeType']) {
          case 'D':
            if (isset($copied_or_moved_map[$path])) {
              if (count($copied_or_moved_map[$path]) > 1) {
                $type = DifferentialChangeType::TYPE_MULTICOPY;
              } else {
                $type = DifferentialChangeType::TYPE_MOVE_AWAY;
              }
            } else {
              $type = DifferentialChangeType::TYPE_DELETE;
              $file_type = $path_file_types[$path];

              if ($file_type == DifferentialChangeType::FILE_DIRECTORY) {
                // Bad. Child paths aren't enumerated in "svn log" so we need
                // to go fishing.

                $list = $this->lookupRecursiveFileList(
                  $repository,
                  $lookup[$path]);

                foreach ($list as $deleted_path => $path_file_type) {
                  $deleted_path = rtrim($path.'/'.$deleted_path, '/');
                  if (!empty($raw_paths[$deleted_path])) {
                    // We somehow learned about this deletion explicitly?
                    // TODO: Unclear how this is possible.
                    continue;
                  }
                  $effects[$deleted_path] = array(
                    'rawPath'         => $deleted_path,
                    'rawTargetPath'   => null,
                    'rawTargetCommit' => null,
                    'rawDirect'       => true,

                    'changeType'      => $type,
                    'fileType'        => $path_file_type,
                  );
                }
              }
            }
            break;
          case 'A':
            $copy_from = $raw_info['rawTargetPath'];
            $copy_rev = $raw_info['rawTargetCommit'];
            if (!strlen($copy_from)) {
              $type = DifferentialChangeType::TYPE_ADD;
            } else {
              if (isset($deleted_paths[$copy_from])) {
                $type = DifferentialChangeType::TYPE_MOVE_HERE;
                $other_type = DifferentialChangeType::TYPE_MOVE_AWAY;
              } else {
                $type = DifferentialChangeType::TYPE_COPY_HERE;
                $other_type = DifferentialChangeType::TYPE_COPY_AWAY;
              }

              $source_file_type = $this->lookupPathFileType(
                $repository,
                $path,
                array(
                  'rawPath'   => $copy_from,
                  'rawCommit' => $copy_rev,
                ));

              if ($source_file_type != DifferentialChangeType::FILE_DIRECTORY) {
                if (isset($raw_paths[$copy_from])) {
                  break;
                }
                $effects[$copy_from] = array(
                  'rawPath'           => $copy_from,
                  'rawTargetPath'     => null,
                  'rawTargetCommit'   => null,
                  'rawDirect'         => false,

                  'changeType'        => $other_type,
                  'fileType'          => $source_file_type,
                );
              } else {
                // ULTRADISASTER. We've added a directory which was copied
                // or moved from somewhere else. This is the most complex and
                // ridiculous case.

                $list = $this->lookupRecursiveFileList(
                  $repository,
                  array(
                    'rawPath'   => $copy_from,
                    'rawCommit' => $copy_rev,
                  ));

                foreach ($list as $from_path => $from_file_type) {
                  $full_from = rtrim($copy_from.'/'.$from_path, '/');
                  $full_to = rtrim($path.'/'.$from_path, '/');

                  if (empty($raw_paths[$full_to])) {
                    $effects[$full_to] = array(
                      'rawPath'         => $full_to,
                      'rawTargetPath'   => $full_from,
                      'rawTargetCommit' => $copy_rev,
                      'rawDirect'       => true,

                      'changeType'      => $type,
                      'fileType'        => $from_file_type,
                    );
                  } else {
                    // This means we picked the file up explicitly elsewhere.
                    // If the file as modified, SVN will drop the copy
                    // information. We need to restore it.
                    $supplemental[$full_to]['rawTargetPath'] = $full_from;
                    $supplemental[$full_to]['rawTargetCommit'] = $copy_rev;
                    if ($raw_paths[$full_to]['rawChangeType'] == 'M') {
                      $resolved_types[$full_to] = $type;
                    }
                  }

                  if (empty($raw_paths[$full_from])) {
                    if ($other_type == DifferentialChangeType::TYPE_COPY_AWAY) {
                      $effects[$full_from] = array(
                        'rawPath'         => $full_from,
                        'rawTargetPath'   => null,
                        'rawTargetCommit' => null,
                        'rawDirect'       => false,

                        'changeType'      => $other_type,
                        'fileType'        => $from_file_type,
                      );
                    }
                  }
                }
              }
            }
            break;
          // This is "replaced", caused by "svn rm"-ing a file, putting another
          // in its place, and then "svn add"-ing it. We do not distinguish
          // between this and "M".
          case 'R':
          case 'M':
            if (isset($copied_or_moved_map[$path])) {
              $type = DifferentialChangeType::TYPE_COPY_AWAY;
            } else {
              $type = DifferentialChangeType::TYPE_CHANGE;
            }
            break;
        }
      }
      $resolved_types[$path] = $type;
    }

    foreach ($raw_paths as $path => $raw_info) {
      $raw_paths[$path]['changeType'] = $resolved_types[$path];
      if (isset($supplemental[$path])) {
        foreach ($supplemental[$path] as $key => $value) {
          $raw_paths[$path][$key] = $value;
        }
      }
    }

    foreach ($raw_paths as $path => $raw_info) {
      $effects[$path] = array(
        'rawPath'         => $path,
        'rawTargetPath'   => $raw_info['rawTargetPath'],
        'rawTargetCommit' => $raw_info['rawTargetCommit'],
        'rawDirect'       => true,

        'changeType'      => $raw_info['changeType'],
        'fileType'        => $path_file_types[$path],
      );
    }

    $parents = array();
    foreach ($effects as $path => $effect) {
      foreach ($this->expandAllParentPaths($path) as $parent_path) {
        $parents[$parent_path] = true;
      }
    }
    $parents = array_keys($parents);

    foreach ($parents as $parent) {
      if (isset($effects[$parent])) {
        continue;
      }

      $effects[$parent] = array(
        'rawPath' => $parent,
        'rawTargetPath' => null,
        'rawTargetCommit' => null,
        'rawDirect' => false,

        'changeType' => DifferentialChangeType::TYPE_CHILD,
        'fileType'   => DifferentialChangeType::FILE_DIRECTORY,
      );
    }

    $lookup_paths = array();
    foreach ($effects as $effect) {
      $lookup_paths[$effect['rawPath']] = true;
      if ($effect['rawTargetPath']) {
        $lookup_paths[$effect['rawTargetPath']] = true;
      }
    }
    $lookup_paths = array_keys($lookup_paths);

    $lookup_commits = array();
    foreach ($effects as $effect) {
      if ($effect['rawTargetCommit']) {
        $lookup_commits[$effect['rawTargetCommit']] = true;
      }
    }
    $lookup_commits = array_keys($lookup_commits);

    $path_map = $this->lookupOrCreatePaths($lookup_paths);
    $commit_map = $this->lookupSvnCommits($repository, $lookup_commits);

    $this->writeChanges($repository, $commit, $effects, $path_map, $commit_map);
    $this->writeBrowse($repository, $commit, $effects, $path_map);
  }

  private function writeChanges(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit,
    array $effects,
    array $path_map,
    array $commit_map) {

    $conn_w = $repository->establishConnection('w');

    $sql = array();
    foreach ($effects as $effect) {
      $sql[] = qsprintf(
        $conn_w,
        '(%d, %d, %d, %nd, %nd, %d, %d, %d, %d)',
        $repository->getID(),
        $path_map[$effect['rawPath']],
        $commit->getID(),
        $effect['rawTargetPath']
          ? $path_map[$effect['rawTargetPath']]
          : null,
        $effect['rawTargetCommit']
          ? $commit_map[$effect['rawTargetCommit']]
          : null,
        $effect['changeType'],
        $effect['fileType'],
        $effect['rawDirect']
          ? 1
          : 0,
        $commit->getCommitIdentifier());
    }

    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE commitID = %d',
      PhabricatorRepository::TABLE_PATHCHANGE,
      $commit->getID());
    foreach (array_chunk($sql, 512) as $sql_chunk) {
      queryfx(
        $conn_w,
        'INSERT INTO %T
          (repositoryID, pathID, commitID, targetPathID, targetCommitID,
            changeType, fileType, isDirect, commitSequence)
          VALUES %Q',
        PhabricatorRepository::TABLE_PATHCHANGE,
        implode(', ', $sql_chunk));
    }
  }

  private function writeBrowse(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit,
    array $effects,
    array $path_map) {

    $conn_w = $repository->establishConnection('w');

    $sql = array();
    foreach ($effects as $effect) {
      $type = $effect['changeType'];

      // Don't write COPY_AWAY to the filesystem table if it isn't a direct
      // event. We do write CHILD.
      if (!$effect['rawDirect']) {
        if ($type == DifferentialChangeType::TYPE_COPY_AWAY) {
          continue;
        }
      }

      if ($effect['rawPath'] == '/') {
        // Don't bother writing the CHILD events on '/' to the filesystem
        // table; in particular, it doesn't have a meaningful parentID.
        continue;
      }

      $existed = !DifferentialChangeType::isDeleteChangeType($type);

      $sql[] = qsprintf(
        $conn_w,
        '(%d, %d, %d, %d, %d, %d)',
        $repository->getID(),
        $path_map[$this->getParentPath($effect['rawPath'])],
        $commit->getCommitIdentifier(),
        $path_map[$effect['rawPath']],
        $existed
          ? 1
          : 0,
        $effect['fileType']);
    }

    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE repositoryID = %d AND svnCommit = %d',
      PhabricatorRepository::TABLE_FILESYSTEM,
      $repository->getID(),
      $commit->getCommitIdentifier());

    foreach (array_chunk($sql, 512) as $sql_chunk) {
      queryfx(
        $conn_w,
        'INSERT INTO %T
          (repositoryID, parentID, svnCommit, pathID, existed, fileType)
          VALUES %Q',
        PhabricatorRepository::TABLE_FILESYSTEM,
        implode(', ', $sql_chunk));
    }

  }

  private function lookupSvnCommits(
    PhabricatorRepository $repository,
    array $commits) {

    if (!$commits) {
      return array();
    }

    $commit_table = new PhabricatorRepositoryCommit();
    $commit_data = queryfx_all(
      $commit_table->establishConnection('w'),
      'SELECT id, commitIdentifier FROM %T WHERE commitIdentifier in (%Ld)',
      $commit_table->getTableName(),
      $commits);

    return ipull($commit_data, 'id', 'commitIdentifier');
  }

  private function lookupPathFileType(
    PhabricatorRepository $repository,
    $path,
    array $path_info) {

    $result = $this->lookupPathFileTypes(
      $repository,
      array(
        $path => $path_info,
      ));

    return $result[$path];
  }

  private function lookupPathFileTypes(
    PhabricatorRepository $repository,
    array $paths) {

    $repository_uri = $repository->getDetail('remote-uri');

    $parents = array();
    $path_mapping = array();
    foreach ($paths as $path => $lookup) {
      $parent = dirname($lookup['rawPath']);
      $parent = ltrim($parent, '/');
      $parent = $this->encodeSVNPath($parent);
      $parent = $repository_uri.$parent.'@'.$lookup['rawCommit'];
      $parent = escapeshellarg($parent);
      $parents[$parent] = true;
      $path_mapping[$parent][] = $path;
    }

    $result_map = array();

    // Reverse this list so we can pop $path_mapping, as that's more efficient
    // than shifting it. We need to associate these maps positionally because
    // a change can copy the same source path from multiple revisions via
    // "svn cp path@1 a; svn cp path@2 b;" and the XML output gives us no way
    // to distinguish which revision we're looking at except based on its
    // position in the document.
    $all_paths = array_reverse(array_keys($parents));
    foreach (array_chunk($all_paths, 64) as $path_chunk) {
      list($raw_xml) = execx(
        'svn --non-interactive --xml ls %C',
        implode(' ', $path_chunk));

      $xml = new SimpleXMLElement($raw_xml);
      foreach ($xml->list as $list) {
        $list_path = (string)$list['path'];

        // SVN is a big mess. See Facebook rG8 (a revision which adds files
        // with spaces in their names) for an example.
        $list_path = rawurldecode($list_path);

        if ($list_path == $repository_uri) {
          $base = '/';
        } else {
          $base = substr($list_path, strlen($repository_uri));
        }

        $mapping = array_pop($path_mapping);
        foreach ($list->entry as $entry) {
          $val = $this->getFileTypeFromSVNKind($entry['kind']);
          foreach ($mapping as $base_path) {
            // rtrim() causes us to handle top-level directories correctly.
            $key = rtrim($base_path, '/').'/'.$entry->name;
            $result_map[$key] = $val;
          }
        }
      }
    }

    foreach ($paths as $path => $lookup) {
      if (empty($result_map[$path])) {
        $result_map[$path] = DifferentialChangeType::FILE_DELETED;
      }
    }

    return $result_map;
  }

  private function encodeSVNPath($path) {
    $path = rawurlencode($path);
    $path = str_replace('%2F', '/', $path);
    return $path;
  }

  private function getFileTypeFromSVNKind($kind) {
    $kind = (string)$kind;
    switch ($kind) {
      case 'dir':   return DifferentialChangeType::FILE_DIRECTORY;
      case 'file':  return DifferentialChangeType::FILE_NORMAL;
      default:
        throw new Exception("Unknown SVN file kind '{$kind}'.");
    }
  }

  private function lookupRecursiveFileList(
    PhabricatorRepository $repository,
    array $info) {

    $path = $info['rawPath'];
    $rev  = $info['rawCommit'];
    $path = $this->encodeSVNPath($path);

    // TODO: This is a scalability nightmare.

    list($raw_xml) = execx(
      'svn --non-interactive --xml ls -R %s%s@%d',
      $repository->getDetail('remote-uri'),
      $path,
      $rev);

    $map = array();

    $xml = new SimpleXMLElement($raw_xml);
    foreach ($xml->list[0] as $entry) {
      $key = (string)$entry->name;
      $file_type = $this->getFileTypeFromSVNKind($entry['kind']);
      $map[$key] = $file_type;
    }

    return $map;
  }

  private function getParentPath($path) {
    $path = rtrim($path, '/');
    $path = dirname($path);
    if (!$path) {
      $path = '/';
    }
    return $path;
  }

  private function expandAllParentPaths($path, $include_self = false) {
    $parents = array();
    if ($include_self) {
      $parents[] = '/'.rtrim($path, '/');
    }
    $parts = explode('/', trim($path, '/'));
    while (count($parts) >= 1) {
      array_pop($parts);
      $parents[] = '/'.implode('/', $parts);
    }
    return $parents;
  }

}







