<?php
class moodle_image {
	private $imagepath;
	private $info;
	private $width;
	private $height;
	private $image;
	private $backup;

	function __construct($img) {
        if(!function_exists('imagecreatefrompng')
            and !function_exists('imagecreatefromjpeg')) {
            throw new moodle_exception('gdnotexist');
        }
        if(!file_exists($img) or !is_readable($img)) {
            throw new moodle_exception('invalidfile');
        }
		
		$this->imagepath = $img;
        unset($img);
		$this->info = getimagesize($this->imagepath);

		switch($this->info['mime']) {
        case 'image/jpeg':
            $this->image = imagecreatefromjpeg($this->imagepath);
            break;
        case 'image/png':
            $this->image = imagecreatefrompng($this->imagepath);
            break;
        case 'image/gif': 
            $this->image = imagecreatefromgif($this->imagepath); 
            break;
        default:
            break;
		}
        if (empty($this->image)) {
            throw new moodle_exception('invalidimage');
        }
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}

	function destroy() {
		 imagedestroy($this->image);
		 imagedestroy($this->backup);
         return true;
	}

	function undo() {
		$this->image = $this->backup;
		return $this;
	}

    function watermark($text='', $pos=array(), $options=array()) {
        global $CFG;
        $text = iconv('ISO-8859-8', 'UTF-8', $text);
        if (empty($options['fontsize'])) {
            if (!empty($options['ttf'])) {
                $options['fontsize'] = 12;
            } else {
                $options['fontsize'] = 1;
            }
        }

        if (empty($options['font'])) {
            $options['font'] = $CFG->libdir . '/default.ttf';
        }
        if (empty($options['angle'])) {
            $options['angle'] = 0;
        }
        $clr = imagecolorallocate($this->image, 255, 255, 255);
        if (!empty($options['ttf'])) {
            imagettftext($this->image,
                $options['fontsize'],        // font size
                $options['angle'],
                $pos[0],
                $pos[1]+$options['fontsize'],
                $clr,
                $options['font'],
                $text);
        } else {
            imagestring($this->image, $options['fontsize'], $pos[0], $pos[1], $text, $clr);
        }
        return $this;
    }

	function rotate($angle=0, $bgcolor=0) {
		$this->image = imagerotate($this->image, $angle, $bgcolor);
		return $this;
	}
	
	function resize($w, $h, $use_resize = true) {
        if(empty($h) && !empty($w)) {
            $h = $this->height * ($w/$this->width);
        }
        if(!empty($h) && empty($w)) {
            $w = $this->width  * ($h/$this->height);
        }
        $new_img = imagecreatetruecolor($w, $h);
        imagealphablending($new_img, false);
        imagecopyresampled($new_img /* dst */, $this->image /* src */, 0, 0, 0, 0, $w, $h, $this->width, $this->height);
        $this->image = $new_img;
		return $this;
	}

	function saveas($imagepath='') {
        if (empty($imagepath)) {
            $imagepath = $this->imagepath;
        }
		switch($this->info['mime']) {
        case 'image/jpeg':
            return imagejpeg($this->image, $imagepath);
            break;
        case 'image/png':
            return imagepng($this->image, $imagepath);
            break;
        case 'image/gif': 
            return imagegif($this->image, $imagepath);
            break;
        default:
            break;
		}
        if(!$this->destroy()) {
            return false;
        } else {
            return $this;
        }
	}

	function display() {
		header('Content-type: '.$this->info['mime']);
		switch($this->info['mime']) {
        case 'image/png':
            imagepng($this->image);
            break;
        case 'image/jpeg':
            imagejpeg($this->image);
            break;
        case 'image/gif':
            imagegif($this->image);
            break;
        default:
            break;
		}
		$this->destroy();
		return $this;
	}
}

