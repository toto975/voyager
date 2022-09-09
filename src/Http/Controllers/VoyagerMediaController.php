<?php

namespace App\Http\Controllers\Voyager;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TCG\Voyager\Http\Controllers\VoyagerMediaController as BaseVoyagerMediaController;
use Illuminate\Support\Facades\Auth;
use TCG\Voyager\Facades\Voyager;

/**
 * Surcharge du code Voyager pour l'affichage du contenu du media manager et du media picker
 */
class VoyagerMediaController extends BaseVoyagerMediaController
{
    /** @var string */
    private $filesystem;

    /** @var string */
    private $directory = '';

    public function __construct()
    {
        $this->filesystem = config('voyager.storage.disk');
    }

    public function files(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');

        $options = $request->details ?? [];
        $thumbnail_names = [];
        $thumbnails = [];
        if (!($options->hide_thumbnails ?? false)) {
            $thumbnail_names = array_column(($options['thumbnails'] ?? []), 'name');
        }

        $folder = $request->folder;

        if ($folder == '/') {
            $folder = '';
        }

        $dir = $this->directory.$folder;

        $files = [
            'dir' => [],
            'files' => []
            ];
        if (class_exists(\League\Flysystem\Plugin\ListWith::class)) {
            $storage = Storage::disk($this->filesystem)->addPlugin(new \League\Flysystem\Plugin\ListWith());
            $storageItems = $storage->listWith(['mimetype'], $dir);
        } else {
            $storage = Storage::disk($this->filesystem);
            $storageItems = $storage->listContents($dir)->sortByPath()->toArray();
        }

        foreach ($storageItems as $item) {
            /**
             * Affichage des dossiers autorisés uniquement :
             *      - ceux qui correspondent aux permissions du modèle
             *      - celui qui correspond au rôle par défaut
             *      - ceux qui correspondent aux rôles additionnels
             */
            $root_folder = explode('/', $item['path']);
            $root_folder = $root_folder[0];
            $dataType = Voyager::model('DataType')->where('slug', '=', $root_folder)->first();

            /*  Si le modèle existe et que l'utilisateur a le droit de naviguer sur ce modèle */
            $permission = $dataType && $this->authorize('browse', app($dataType->model_name));

            /*  Si le rôle par défaut de l'utilisateur existe et que le nom du rôle par défaut est le même que celui du dossier */
            $role_principal = Auth::user()->role->name && Str::lower(Auth::user()->role->name) == $root_folder;

            /*  Si des rôles additionnels de l'utilisateur existent et que leur nom est le même que celui du dossier */
            $roles_secondaires = Auth::user()->roles->pluck('name')->contains($root_folder);

            /* Alors on va afficher le dossier et ses fichiers */
            if($permission || $role_principal || $roles_secondaires) {
                if ($item['type'] == 'dir') {
                    $files['dir'][] = [
                        'name'          => $item['basename'] ?? basename($item['path']),
                        'type'          => 'folder',
                        'path'          => Storage::disk($this->filesystem)->url($item['path']),
                        'relative_path' => $item['path'],
                        'items'         => '',
                        'last_modified' => '',
                    ];
                } else {
                    if (empty(pathinfo($item['path'], PATHINFO_FILENAME)) && !config('voyager.hidden_files')) {
                        continue;
                    }
                    // Its a thumbnail and thumbnails should be hidden
                    if (Str::endsWith($item['path'], $thumbnail_names)) {
                        $thumbnails[] = $item;
                        continue;
                    }
                    $mime = 'file';
                    if (class_exists(\League\MimeTypeDetection\ExtensionMimeTypeDetector::class)) {
                        $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())->detectMimeTypeFromFile($item['path']);
                    }
		            /* Dans certains cas, le type mime n'existe pas ou vaut null */
					/* Cela provoque une erreur côté js, on exclut les fichiers concernés */
                     if ((isset($item['mimetype']) && $item['mimetype'] != null) || (isset($mime) && $mime != null)) {
						$files['files'][] = [
							'name'          => $item['basename'] ?? basename($item['path']),
							'filename'      => $item['filename'] ?? basename($item['path'], '.'.pathinfo($item['path'])['extension']),
							'type'          => $item['mimetype'] ?? $mime,
							'path'          => Storage::disk($this->filesystem)->url($item['path']),
							'relative_path' => $item['path'],
							'size'          => $item['size'] ?? $item->fileSize(),
							'last_modified' => $item['timestamp'] ?? $item->lastModified(),
							'thumbnails'    => [],
						];
					 }
                }
            }
        }
        if (isset($request->sort) && $request->sort == 'desc') {
            $name = array_column($files['dir'], 'name');
            array_multisort($name, SORT_DESC, SORT_NATURAL, $files['dir']);
            $name = array_column($files['files'], 'name');
            array_multisort($name, SORT_DESC, SORT_NATURAL, $files['files']);
        } else {
            $name = array_column($files['dir'], 'name');
            array_multisort($name, SORT_ASC, SORT_NATURAL, $files['dir']);
            $name = array_column($files['files'], 'name');
            array_multisort($name, SORT_ASC, SORT_NATURAL, $files['files']);
        }
        $files = array_merge($files['dir'], $files['files']);
        foreach ($files as $key => $file) {
            foreach ($thumbnails as $thumbnail) {
                if ($file['type'] != 'folder' && Str::startsWith($thumbnail['filename'], $file['filename'])) {
                    $thumbnail['thumb_name'] = str_replace($file['filename'].'-', '', $thumbnail['filename']);
                    $thumbnail['path'] = Storage::disk($this->filesystem)->url($thumbnail['path']);
                    $files[$key]['thumbnails'][] = $thumbnail;
                }
            }
        }

        return response()->json($files);
    }

}
