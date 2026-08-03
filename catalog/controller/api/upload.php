<?php
class ControllerApiUpload extends Controller {
    public function index() {
        $json = array();

        // Check if a file was sent using the "file" parameter
        if (!empty($this->request->files['file']['name']) && is_file($this->request->files['file']['tmp_name'])) {
            
            // 1. Sanitize the filename
            $filename = basename(html_entity_decode($this->request->files['file']['name'], ENT_QUOTES, 'UTF-8'));
            
            // 2. Validate filename length
            if ((utf8_strlen($filename) < 3) || (utf8_strlen($filename) > 255)) {
                $json['error'] = 'Filename must be between 3 and 255 characters!';
            }

            // 3. Validate file extension (Updated with your list)
            $allowed_extensions = array(
                'zip', 'txt', 'png', 'jpe', 'jpeg', 'jpg', 'gif', 'bmp', 'ico', 
                'tiff', 'tif', 'svg', 'svgz', 'webp', 'rar', 'msi', 'cab', 'mp3', 
                'qt', 'mov', 'pdf', 'psd', 'ai', 'eps', 'ps', 'doc'
            );
            $extension = utf8_strtolower(utf8_substr(strrchr($filename, '.'), 1));
            
            if (!in_array($extension, $allowed_extensions)) {
                $json['error'] = 'Invalid file extension!';
            }

            // 4. Validate MIME type (Updated with your list, cleaned up quotes)
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

            // 5. Check for standard PHP upload errors
            if ($this->request->files['file']['error'] != UPLOAD_ERR_OK) {
                $json['error'] = 'Upload error code: ' . $this->request->files['file']['error'];
            }

            // 6. Process the upload if there are no errors
            if (!isset($json['error'])) {
                // Note: Currently saving everything to the image directory
                $directory = DIR_IMAGE . 'catalog/';
                
                // Optional: Prepend a timestamp to ensure unique filenames
                // $filename = time() . '_' . $filename;
                
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

        // Return the JSON response properly
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}