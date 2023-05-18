<?php
namespace Itskodinger\Midia\Controller;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Image;

class MidiaController extends Controller {
    protected $directory;
    protected $directory_name;
    protected $url_prefix;
    protected $thumbs, $default_thumb;
    protected $imageTypes;
    protected $s3Client, $bucketName;

    public function __construct() {
        $this->url_prefix = config('midia.url_prefix');

        if (request()->has('directory_name')) {
            $directoryName = request()->get('directory_name', null);
        }

        if(isset($directoryName)) {
            $currentDirectory = config('midia.directories.' . $directoryName, null);

            if($currentDirectory == null) {
                $this->directory = $directoryName;
            }else{
                $this->directory = $currentDirectory['path'];
                $this->directory_name = $currentDirectory['name'];
            }
        }else{
            $this->directory = config('midia.directory');
            $this->directory_name = config('midia.directory_name');
        }

        if($this->url_prefix == $this->directory_name) {
            $this->url_prefix = '';
        }

        $this->url_prefix .= '/';

        // thumbnail
        $this->thumbs = config('midia.thumbs');
        if(!in_array(250, $this->thumbs)) $this->thumbs[count($this->thumbs)] = 250;

        $this->default_thumb = 'thumbs-250';

        $this->imageTypes = [
            'image/jpg',
            'image/jpeg',
            'image/pjpeg',
            'image/png',
            'image/x-png',
            'image/gif',
            'image/webp',
            'image/x-webp'
        ];

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => env('region'),
            'endpoint' => env('endpoint'),
            'credentials' => [
                'key' => env('key'),
                'secret' => env('secret'),
            ],
        ]);

        $this->bucketName = env('bucket');
    }

    private function is_aws_on(){
        return strstr($this->directory, 'aws') ? true : false;
    }

    public function url($path='') {
        return url($this->url_prefix . $path);
    }

    public function index($limit) {
        $dir = $this->is_aws_on() ? $this->index_aws($limit) : $this->index_local($limit);
        return response($dir, 200)
            ->header('Content-Type', 'application/json');
    }

    private function index_aws($limit){
        $dir = $this->directory;
        if(strstr($dir, 'public/')){
            $dir = explode('public/', $this->directory)[1];
        }

        $folderExists = $this->s3Client->doesObjectExistV2($this->bucketName, $dir . '/');

        if(!$folderExists){
            $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $dir . '/',
                'Body' => '',
                'ContentType' => 'application/x-directory',
            ]);
        }
        
        try {
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucketName,
                'Prefix' => $dir . '/'
            ]);
        } catch (S3Exception $e) {
            dd($e->getMessage());
        }

        $exec = [];
        $delimiter = '~!~or|!|';
        $files = [];

        foreach ($objects['Contents'] as $i => $object){
            $key = $object['Key'];
            $metadata_ = $this->s3Client->headObject([
                            'Bucket' => $this->bucketName,
                            'Key' => $key
                        ]);

            $dir_or_file = explode($dir . '/', $key);
            if($dir_or_file[1]){
                if(!strstr($dir_or_file[1], '/')){
                    $files[$dir_or_file[1] . $delimiter . $metadata_['LastModified']->getTimeStamp() . $delimiter . $metadata_['ContentLength']] = $metadata_['LastModified']->getTimeStamp();
                }
            }
        }

        $q = request()->key;

        arsort($files);
        $files = array_keys($files);
        $exec = $files;

        $thumbs = $this->thumbs;
        foreach($thumbs as $i => $t) {
            $thumbs[$i] = 'thumbs-' . $t;
        }

        $_files = [];
        foreach($exec as $i => $item) {
            $meta = explode($delimiter, $item);
                // if(in_array(mime_content_type($dir . '/' . $meta[0]), $this->imageTypes))
                //     $this->_resize($meta[0]);
                $_files[$i]['fullname'] = $meta[0];
                $_files[$i]['name'] = pathinfo($meta[0], PATHINFO_FILENAME);
                $_files[$i]['url'] = env('endpoint') . '/' . $this->bucketName . '/' . $dir . '/' . $meta[0];
                $_files[$i]['thumbnail'] = $dir . '/' . $this->default_thumb . '/' . $meta[0];
                $_files[$i]['extension'] = strtolower(pathinfo($meta[0],PATHINFO_EXTENSION));
                $_files[$i]['size'] = $this->toMb($meta[2]);
                $_files[$i]['filetime'] = midia_time_elapsed($meta[1]);
            // }
        }

        $total_all = count($_files);

        if(isset($q) && trim($q) !== '') {
            $_search = [];
            foreach($_files as $key => $file) {
                if(strpos($file['fullname'], $q) !== false) {
                    $_search[$key] = $file;
                }
            }
            $_files = $_search;
        }

        $page = $limit;
        $perPage = 20;
        $offset = ($page * $perPage) - $perPage;

        $exec = array_slice($_files, $offset, $perPage);
        return json_encode(["files" => $exec, "total" => count($_files), "total_all" => $total_all]);
    }

    private function index_local($limit){
        $dir = $this->directory;
        $q = request()->key;

        if(!is_dir($dir)) {
            // create directory if not found
            mkdir($dir);
        }

        $exec = scandir($dir);
        $exec = array_splice($exec, 2);

        $files = [];
        foreach($exec as $file) {
            $files[$file] = filemtime($dir . '/' . $file);
        }

        arsort($files);
        $files = array_keys($files);
        $exec = $files;

        $thumbs = $this->thumbs;
        foreach($thumbs as $i => $t) {
            $thumbs[$i] = 'thumbs-' . $t;
        }

        $_files = [];
        foreach($exec as $i => $item) {
            if(!is_dir($this->directory . '/' . $item)) {
                if(in_array(mime_content_type($this->directory . '/' . $item), $this->imageTypes))
                    $this->_resize($item);

                $_files[$i]['fullname'] = $item;
                $_files[$i]['name'] = pathinfo($item, PATHINFO_FILENAME);
                $_files[$i]['url'] = $this->url($this->directory_name . '/' . $item);
                $_files[$i]['thumbnail'] = $this->url($this->directory_name . '/' . $this->default_thumb . '/' . $item);
                $_files[$i]['extension'] = strtolower(pathinfo($item,PATHINFO_EXTENSION));
                $_files[$i]['size'] = $this->toMb(filesize($this->directory . '/' . $item));
                $_files[$i]['filetime'] = midia_time_elapsed(filemtime($this->directory . '/' . $item));
            }
        }

        $total_all = count($_files);

        if(isset($q) && trim($q) !== '') {
            $_search = [];
            foreach($_files as $key => $file) {
                if(strpos($file['fullname'], $q) !== false) {
                    $_search[$key] = $file;
                }
            }
            $_files = $_search;
        }

        $page = $limit;
        $perPage = 20;
        $offset = ($page * $perPage) - $perPage;

        $exec = array_slice($_files, $offset, $perPage);
        return json_encode(["files" => $exec, "total" => count($_files), "total_all" => $total_all]);
    }

    public function toMb($bytes) {
        for ($i=0;$bytes>=1024&&$i<5;$i++)
            $bytes/=1024;

        return round($bytes,2).[' B',' KB',' MB',' GB',' TB',' PB'][$i];
    }

    public function open($editor) {
        return view('midia::app', compact('editor'));
    }

    public function delete($file) {
        $this->is_aws_on() ? $this->delete_aws($file) : $this->delete_local($file);
        return response(['data' => 'ok'], 200)
            ->header('Content-Type', 'application/json');
    }

    private function delete_aws($file){
        $dir = $this->directory;
        if(strstr($dir, 'public/')){
            $dir = explode('public/', $this->directory)[1];
        }

        $this->s3Client->deleteObject([
            'Bucket' => $this->bucketName,
            'Key' => $dir . '/' . $file
        ]);
    }

    private function delete_local($file){
        unlink(($this->directory . '/' . $file));
    }

    public function upload(Request $request) {
        return $this->is_aws_on() ? $this->upload_aws($request) : $this->upload_local($request);
    }

    private function upload_aws($request){
        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $wo_extension = basename($fileName, "." . $file->getClientOriginalExtension());

        $inc = 1;

        $dir = $this->directory;
        if(strstr($dir, 'public/')){
            $dir = explode('public/', $this->directory)[1];
        }

        $fileExists = $this->s3Client->doesObjectExistV2($this->bucketName, $dir . '/' . $fileName);

        while($fileExists) {
            $name = $wo_extension . '-' . $inc;
            $fileName = $name . '.' . $file->getClientOriginalExtension();
            $inc++;
        }
        
        $this->s3Client->putObject([
            'Bucket' => $this->bucketName,
            'Key' => $dir . '/' . $fileName,
            'SourceFile' => $file,
        ]);

        return response()->json(['success'=>$fileName]);
    }

    private function upload_local($request){
        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $wo_extension = basename($fileName, "." . $file->getClientOriginalExtension());

        $inc = 1;

        while(file_exists($this->directory . '/' . $fileName)) {
            $name = $wo_extension . '-' . $inc;
            $fileName = $name . '.' . $file->getClientOriginalExtension();
            $inc++;
        }

        $file->move($this->directory, $fileName);

            //Resize
        if(in_array(mime_content_type($this->directory . '/' . $fileName), $this->imageTypes)) {
            $this->_resize($fileName);
        }
        return response()->json(['success'=>$fileName]);
    }
    public function _resize($fileName) {
        $thumbs = $this->thumbs;
        foreach($thumbs as $thumb) {
            $thumb_folder = 'thumbs-' . $thumb;

            if(!is_dir($this->directory . '/' . $thumb_folder))
                mkdir($this->directory . '/' . $thumb_folder);

            $file = $this->directory . '/' . $fileName;
            $thumb_file = $this->directory . '/' . $thumb_folder . '/' . $fileName;

            if(file_exists($thumb_file)) continue;

            $image = Image::make($file);
            $image->fit($thumb);
            $image->save($thumb_file);
        }
    }

    public function rename(Request $request, $file) {
        $fileName = $request->newName;
        $wo_extension = pathinfo($fileName, PATHINFO_FILENAME);
        $_extension = explode(".", $fileName);
        $extension = end($_extension);

        $inc = 1;
        while(file_exists($this->directory . '/' . $fileName)) {
            $name = $wo_extension . '-' . $inc;
            $fileName = $name . '.' . $extension;
            $inc++;
        }

        rename($this->directory . '/' . $file, $this->directory . '/' . $fileName);
        $thumbs = $this->thumbs;
        foreach($thumbs as $thumb) {
            $thumb_folder = 'thumbs-' . $thumb;
            rename($this->directory . '/' . $thumb_folder . '/' . $file, $this->directory . '/' . $thumb_folder . '/' . $fileName);
        }

        $new_data = [];
        $new_data['fullname'] = $fileName;
        $new_data['name'] = pathinfo($fileName, PATHINFO_FILENAME);
        $new_data['url'] = $this->url($this->directory_name . '/' . $fileName);
        $new_data['thumbnail'] = $this->url($this->directory_name . '/' . $this->default_thumb . '/' . $fileName);

        return response()->json(['success'=>$new_data]);
    }
}
