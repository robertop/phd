<?php
namespace phpdotnet\phd;
/* $Id$ */

/**
 * This class will render a CTags file containing all of the classes, functions, defines that
 * come in PHP.
 */
class Package_IDE_CTags extends Package_IDE_Base {

	/**
	 * The opened file pointer (resource) to the tag file that is being created
	 */
	private $tagFile;
	
	/**
	 * The captured class info (class name, inheritance)
	 */
	private $currentClassInfo;
	
	/**
	 * Flag that will be set when the XML reader is parsing the classsynopsis tag.
	 * This will help in  skipping over <classname> nodes that we dont want
	 */
	private $inClassSynopsis;

    public function __construct() {
        $this->registerFormatName('CTags');
        $this->setExt(Config::ext() === null ? ".php" : Config::ext());
		$this->tagFile = NULL;
		$this->inClassSynopsis = FALSE;
    }
	
	/**
	 * When this format is constructed, create the tags file where all of the tags will go.
	 * We only want 1 tag file for the entire list of functions.
	 */
	public function INIT($value) {
		parent::INIT($value);
		$fileName = Config::output_dir() . strtolower($this->getFormatName()) . '/php.tags';
		$this->tagFile = fopen($fileName, 'wb');
		if (!$this->tagFile) {
			 v("Output file '{$fileName}' cannot be opened for writing.", E_USER_ERROR);
		}
	}
	
	/**
	 * properly close the file after we have consumed throught all of the functions.
	 */
	public function FINALIZE($value) {
		parent::FINALIZE($value);
		if ($this->tagFile) {
			fclose($this->tagFile);
			$this->tagFile = NULL;
		}
	}
	
	public function STANDALONE($value) {
		//$this->elementmap['phpdoc:classref'] = 'format_classref';
		$this->elementmap['classsynopsisinfo'] = 'format_classsynopsisinfo';
		$this->textmap['classname'] = 'format_classname_text';
		parent::STANDALONE($value);
	}
	
	/**
	 * override this method so that we write to the opened file pointer.
	 */
	public function format_refentry($open, $name, $attrs, $props) {
		if (!$this->tagFile) {
			return;
		}
        if (!$this->isFunctionRefSet) {
            return;
        }
        if ($open) {
            $this->function = $this->dfunction;
            $this->cchunk = $this->dchunk;

            $this->function['manualid'] =  $attrs[Reader::XMLNS_XML]['id'];
            return;
        }
        if (!isset($this->cchunk['funcname'][0])) {
             return;
        }
        if (false !== strpos($this->cchunk['funcname'][0], ' ')) {
            return;
        }
        $this->function['name'] = $this->cchunk['funcname'][0];
        $this->function['version'] = $this->versionInfo($this->function['name']);
        $data = $this->renderFunction();
		
		// guarantee only 1 newline
		$data = trim($data);
		fwrite($this->tagFile, $data);
		fwrite($this->tagFile, "\n");
    }
	
	public function format_classsynopsisinfo($open, $name, $attrs, $props) {
		if ($open) {
			$this->inClassSynopsis = TRUE;
			// clear out any previous info whe the class tag is opened
			$this->currentClassInfo = array(
				'modifier' => '',
				'name' => '',
				'extends' => array(),
				'implements' => array()
			);
			return;
		}
		$this->inClassSynopsis = FALSE;
		
		// check for empty class names, dont add them to the tags file
		if (!$this->currentClassInfo['name']) {
			return;
		}
		$data = $this->renderClass();
		
		// guarantee only 1 newline
		$data = trim($data);
		fwrite($this->tagFile, $data);
		fwrite($this->tagFile, "\n");
	}
	
	public function format_classname_text($value, $tag) {
		if (!$this->inClassSynopsis) {
			return;
		}
		$this->currentClassInfo['name'] = $value;
	}
	
    private function renderFunction() {
		$name = trim($this->function['name']);
		$isMethod = FALSE;
		$className = '';
		$dotIndex = stripos($name, '.');
		if ($dotIndex !== FALSE) {
		
			// this is a method. parse the class name out of ir
			$isMethod = TRUE;
			$className = substr($name, 0, $dotIndex);
			$name = substr($name, $dotIndex + 1);
		}
		$fileName = '';
		$signature = $this->renderFunctionDefinition($name);
		$returnType = $this->function['return']['type'];
        $str = $name . "\t" . $fileName . "\t" .  $signature . "\t";
		if ($isMethod) {
			$str .= 'kind:f';
			$str .= "\t";
			$str .= 'class:';
			$str .= $className;
		}
		else {
			$str .= 'f';
		}
		return $str;
    }	

    private function renderFunctionDefinition($functionName) {
	
		// surround the signature with the VIM 'magic'
		// also use TAG file format 2 (add a VIM comment at the end)
        return  
			"/^" . 
			"function {$functionName}({$this->renderParamBody()})" . 
			"/;\"";
    }
	
    private function renderParamBody() {
        $result = array();
        foreach($this->function['params'] as $param) {
            if ($param['optional'] && isset($param['initializer'])) {
                $result[] = "\${$param['name']} = {$param['initializer']}";
            } else {
                $result[] = "\${$param['name']}";
            }
        }

        return implode(", ", $result);
    }
	
	private function renderClass() {
		$name = $this->currentClassInfo['name'];
		$extends = join(',', array_merge($this->currentClassInfo['extends'], $this->currentClassInfo['implements']));
		$data = "{$name}\t \t/^class {$name}/;\"\tc\t";
		if ($extends) {
			$data .= 'i:' . $extends;
		}
		return $data;
	}
	
	// will not use this because we want to render all functions in 1 file
	public function parseFunction() {}
}

/*
* vim600: sw=4 ts=4 syntax=php et
* vim<600: sw=4 ts=4
*/
