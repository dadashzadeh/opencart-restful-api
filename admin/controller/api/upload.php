<?php
class ControllerApiUpload extends Controller {
    
    private $allowed_extensions = array(
        'zip', 'txt', 'png', 'jpe', 'jpeg', 'jpg', 'gif', 'bmp', 'ico', 
        'tiff', 'tif', 'svg', 'svgz', 'webp', 'rar', 'msi', 'cab', 'mp3', 
        'qt', 'mov', 'pdf', 'psd', 'ai', 'eps', 'ps', 'doc'
    );

    public function index() {
        $json = array();

        if (!empty($this->request->files['file']['name']) && is_file($this->request->files['file']['tmp_name'])) {
            
            $filename = basename(html_entity_decode($this->request->files['file']['name'], ENT_QUOTES, 'UTF-8'));
            
            if ((utf8_strlen($filename) < 3) || (utf8_strlen($filename) > 255)) {
                $json['error'] = 'Filename must be between 3 and 255 characters!';
            }

            $extension = utf8_strtolower(utf8_substr(strrchr($filename, '.'), 1));
            if (!in_array($extension, $this->allowed_extensions)) {
                $json['error'] = 'Invalid file extension!';
            }

            $allowed_mime_types = array(
                'text/plain', 'image/png', 'image/jpeg', 'image/gif', 'image/bmp', 
                'image/tiff', 'image/svg+xml', 'image/webp', 'application/zip', 
                'application/x-zip', 'application/x-zip-compressed', 'application/rar', 
                'application/x-rar', 'application/x-rar-compressed', 'application/octet-stream', 
                'audio/mpeg', 'video/quicktime', 'application/pdf'
            );
            
            if (!in_array($this->request->files['file']['type'], $allowed_mime_types)) {
                $json['error'] = 'Invalid MIME type!';
            }

            if ($this->request->files['file']['error'] != UPLOAD_ERR_OK) {
                $json['error'] = 'Upload error code: ' . $this->request->files['file']['error'];
            }

            if (!isset($json['error'])) {
                $directory = DIR_IMAGE . 'catalog/';
                
                if (move_uploaded_file($this->request->files['file']['tmp_name'], $directory . $filename)) {
                    $json['success'] = 'File uploaded successfully!';
                    $json['file_path'] = 'catalog/' . $filename; 
                } else {
                    $json['error'] = 'Failed to move the uploaded file. Check directory permissions.';
                }
            }
        } else {
            $json['error'] = 'No file uploaded or parameter name is not "file".';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function delete() {
        $json = array();

        if (isset($this->request->post['filename'])) {
            $filename = basename(html_entity_decode($this->request->post['filename'], ENT_QUOTES, 'UTF-8'));
            $file_path = DIR_IMAGE . 'catalog/' . $filename;

            if (file_exists($file_path) && is_file($file_path)) {
                if (unlink($file_path)) {
                    $json['success'] = 'File deleted successfully!';
                } else {
                    $json['error'] = 'Failed to delete the file. Check permissions.';
                }
            } else {
                $json['error'] = 'File not found!';
            }
        } else {
            $json['error'] = 'Filename parameter is required!';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function rename() {
        $json = array();

        if (isset($this->request->post['old_name']) && isset($this->request->post['new_name'])) {
            $old_name = basename(html_entity_decode($this->request->post['old_name'], ENT_QUOTES, 'UTF-8'));
            $new_name = basename(html_entity_decode($this->request->post['new_name'], ENT_QUOTES, 'UTF-8'));
            
            $old_path = DIR_IMAGE . 'catalog/' . $old_name;
            $new_path = DIR_IMAGE . 'catalog/' . $new_name;

            $new_extension = utf8_strtolower(utf8_substr(strrchr($new_name, '.'), 1));

            if (!in_array($new_extension, $this->allowed_extensions)) {
                $json['error'] = 'Invalid file extension for the new name! You cannot change file type to an insecure format.';
            } elseif (!file_exists($old_path) || !is_file($old_path)) {
                $json['error'] = 'Original file not found!';
            } elseif (file_exists($new_path)) {
                $json['error'] = 'A file with the new name already exists!';
            } else {
                if (rename($old_path, $new_path)) {
                    $json['success'] = 'File renamed successfully!';
                    $json['new_file_path'] = 'catalog/' . $new_name;
                } else {
                    $json['error'] = 'Failed to rename the file. Check permissions.';
                }
            }
        } else {
            $json['error'] = 'Both old_name and new_name parameters are required!';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}