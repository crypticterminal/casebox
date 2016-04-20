<?php
namespace CB;

/*
selecting node properties from the tree
comparing last preview access time with node update time. Generate preview if needed and store it in cache
checking if preview is available and return it
 */

if (empty($_GET['subcommand'])) {
    exit(0);
}

//init
require_once 'init.php';

$coreUrl = Config::get('core_url');
$filesPreviewDir = Config::get('files_preview_dir');

$command = basename($_GET['command']); //view | print

// get requested filename
$filename = basename($_GET['subcommand']);

$f = explode('.', $filename);
$a = array_shift($f);
@list($id, $version_id) = explode('_', $a);
$ext = array_pop($f);

// check login
if (!User::isLoged()) {
    if (!empty($_GET['i'])) { //internal request (user for previews)
        echo "<authenticate />";
    } else {
        header('Location: ' . $coreUrl . 'login.php?view=' . $id);
    }
    exit(0);
}

if (empty($ext)) {
    //check access with security model
    if (!Security::canRead($id)) {
        echo L\get('Access_denied');
        exit(0);
    }

} else { //this should be an additional file generated by the preview process (libreoffice)
    $f = realpath($filesPreviewDir . $filename);

    if (file_exists($f)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        header('Content-type: ' . finfo_file($finfo, $f));
        echo file_get_contents($f);
    }
    exit(0);
}

if (!is_numeric($id)) {
    exit(0);
}

$toolbarItems = array(
    '<a href="' . $coreUrl . '?locate=' . $id . '">' . L\get('OpenInCasebox') .'</a>'
);

$obj = Objects::getCachedObject($id);
$objData = $obj->getData();
$objType = $obj->getType();

//add header and css for externa view
if (($command == 'print') || empty($_GET['i'])) {
    require_once(LIB_DIR . 'MinifyCache.php');
    echo '<html><head>
        <meta http-equiv="content-type" content="text/html; charset=utf-8">
        <link rel="stylesheet" type="text/css" href="' . $coreUrl . substr(getMinifyGroupUrl('preview'), 1) . '" /></head>';

    if ($command == 'print') {
        echo '<body class="external" onLoad="window.print()">';

    } else {// if external window then print the toolbar
        echo '<body class="external">';
        if ($objType == 'file') {
            $toolbarItems[] = '<a href="' . $coreUrl . 'download/' . $id . '/">' . L\get('Download') .'</a>';
        }

        echo '<table border="0" cellspacing="12" cellpading="12"><tr><td>'.implode('</td><td>', $toolbarItems).'</td></tr></table>';
    }
}

$preview = array();

switch ($obj->getType()) {

    case 'file':
        $sql = 'SELECT p.filename
            FROM files f
            JOIN file_previews p ON f.content_id = p.id
            WHERE f.id = $1';

        if (!empty($version_id)) {
            $sql = 'SELECT p.filename
                FROM files_versions f
                JOIN file_previews p ON f.content_id = p.id
                WHERE f.file_id = $1
                    AND f.id = $2';
        }

        $res = DB\dbQuery($sql, array($id, $version_id));

        if ($r = $res->fetch_assoc()) {
            if (!empty($r['filename']) && file_exists($filesPreviewDir . $r['filename'])) {
                $preview = $r;
            }
        }
        $res->close();

        if (empty($preview)) {
            $preview = Files::generatePreview($id, $version_id);
        }

        if (!empty($preview['processing'])) {
            echo '&#160';

        } else {
            $top = '';
            // $tmp = Tasks::getActiveTasksBlockForPreview($id);
            // if (!empty($tmp)) {
            //     $top = '<div class="obj-preview-h pt10">'.L\get('ActiveTasks').'</div>'.$tmp;
            // }
            if (!empty($top)) {
                echo //'<div class="p10">'.
                $top.
                // '</div>'.
                '<hr />';
            }

            if (!empty($preview['filename'])) {
                $fn = $filesPreviewDir . $preview['filename'];
                if (file_exists($fn)) {
                    echo file_get_contents($fn);
                    $res = DB\dbQuery(
                        'UPDATE file_previews
                        SET ladate = CURRENT_TIMESTAMP
                        WHERE id = $1',
                        $id
                    );
                }
            } elseif (!empty($preview['html'])) {
                echo $preview['html'];
            }
            // $dbNode = new TreeNode\Dbnode();
            // echo '<!-- NodeName:'.$dbNode->getName($id).' -->';
        }
        break;

    default:
        $preview = array();
        $o = new Objects();
        $pd = $o->getPluginsData(array('id' => $id));
        $title = '';

        if (!empty($pd['data']['objectProperties'])) {
            $data = $pd['data']['objectProperties']['data'];
            $title = '<div class="obj-header"><b class="">' . $data['name'] . '</div>';
            $preview = $data['preview'];
        }

        echo $title . implode("\n", $preview);
        break;
}

if (empty($_GET['i'])) {
    echo '</body></html>';
}
