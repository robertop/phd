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
	 * The captured constant info (constant name)
	 */
	private $currentDefineInfo;
	
	/**
	 * Flag that will be set when the XML reader is parsing the classsynopsis tag.
	 * This will help in skipping over <classname> nodes that we dont want
	 */
	private $inClassSynopsis;
	
	/**
	 * Flag that will be set when the XML reader is parsing the ooclass tag.
	 * This will help in determining if the class name is a baseclass or not
	 */
	private $inOOClass;
	
	/**
	 * Flag that will be set when the XML reader is parsing the <modifier> tag.
	 * This will help in determining if the class name is a baseclass or not
	 */
	private $inClassExtends;
	
	/**
	 * Flag that will be set when the XML reader is parsing the <appendix> tag.
	 * This will help in determining predefined constants
	 */
	private $inAppendix;
	
	/**
	 * Flag that will be set when the XML reader is parsing the <varlistentry> tag.
	 * This will help in determining predefined constants
	 */
	private $inVarlistEntry;

	/**
	 * Flag that will be set when the XML reader is parsing the <term> tag.
	 * This will help in determining predefined constants
	 */
	private $inTerm;	
	/**
	 * Most exceptions don't have their  own <classsynopsisinfo> info, they are just 
	 * documented in the method comments themselves; however  since multiple methods
	 * may throw the same type of exception we store the exceptions that have already 
	 * been rendered so that the tags file does not contain duplicates.
	 */
	private $completedExceptions;

    public function __construct() {
        $this->registerFormatName('CTags');
        $this->setExt(Config::ext() === null ? ".php" : Config::ext());
		$this->tagFile = NULL;
		$this->inClassSynopsis = FALSE;
		$this->completedExceptions = array();
		$this->inOOClass = FALSE;
		$this->inClassExtends = FALSE;
		$this->inAppendix = FALSE;
		$this->inVarlistEntry = FALSE;
		$this->inTerm = FALSE;
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
		
		// the nodes that contain class info
		$this->elementmap['classsynopsisinfo'] = 'format_classsynopsisinfo';
		$this->elementmap['ooclass'] = 'format_ooclass';
		$this->textmap['classname'] = 'format_classname_text';
		$this->textmap['modifier'] = 'format_modifier_text';
		$this->textmap['interfacename'] = 'format_interfacename_text';
		
		// the nodes that contain constants [define()'s] info
		$this->elementmap['appendix'] = 'format_appendix';
		$this->elementmap['term'] = 'format_term';
		$this->elementmap['varlistentry'] = 'format_varlistentry';
		$this->textmap['constant'] = 'format_constant_text';
		
		parent::STANDALONE($value);
	}
	
	/**
	 * captures the class name when traversing the classsynopsisinfo node
	 */
	public function format_classsynopsisinfo($open, $name, $attrs, $props) {
		if ($open) {
			$this->inClassSynopsis = TRUE;
			// clear out any previous info whe the class tag is opened
			$this->currentClassInfo = array(
				'modifier' => '',
				'name' => '',
				'extends' => '',
				'implements' => array()
			);
			return;
		}
		$this->inClassSynopsis = FALSE;
		$this->inClassExtends = FALSE;
		
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
	
	public function format_ooclass($open, $name, $attrs, $props) {
		if (!$this->inClassSynopsis) {
			return;
		}
		$this->inOOClass = $open;
	}
	
	public function format_classname_text($value, $tag) {
		if (!$this->inClassSynopsis) {
			return;
		}
		if ($this->inOOClass && $this->inClassExtends) {
			$this->currentClassInfo['extends'] = $value;
		}
		else if ($this->inOOClass) {
			$this->currentClassInfo['name'] = $value;
		}
	}
	
	public function format_modifier_text($value, $tag) {
		if (!$this->inClassSynopsis) {
			return;
		}
		$this->inClassExtends = strcasecmp($value, 'extends') == 0;
	}
	
	public function format_interfacename_text($value, $tag) {
		if (!$this->inClassSynopsis) {
			return;
		}
		$this->currentClassInfo['implements'][] = $value;
	}
	
	private function renderClass() {
		$name = $this->currentClassInfo['name'];
		$allBases = array();
		if ($this->currentClassInfo['extends']) {
			$allBases[] = $this->currentClassInfo['extends'];
		}
		if ($this->currentClassInfo['implements']) {
			array_merge($allBases, $this->currentClassInfo['implements']);
		}
		$extends = join(',', $allBases);
		$data = "{$name}\t \t/^class {$name}/;\"\tc\t";
		if ($extends) {
			$data .= 'i:' . $extends;
		}
		return $data;
	}
	
	public function format_appendix($open, $name, $attrs, $props) {
		$this->inAppendix = $open;
	}
	
	public function format_varlistentry($open, $name, $attrs, $props) {
		if (!$this->inAppendix) {
			return;
		}
		$this->inVarlistEntry = $open;
		if ($open) {
			$this->currentDefineInfo = '';
			return;
		}
		if ($this->currentDefineInfo) {
			$data = $this->renderDefine();
			
			// guarantee only 1 newline
			$data = trim($data);
			fwrite($this->tagFile, $data);
			fwrite($this->tagFile, "\n");$this->renderDefine();
		}
	}
	
	public function format_term($open, $name, $attrs, $props) {
		$this->inTerm = $this->inVarlistEntry && $open;
	}
	
	private function renderDefine() {			
		$signature = "/^define('{$this->currentDefineInfo}', '')/;\"";
		return $this->currentDefineInfo . "\t" . '' . "\t" . $signature . "\t" . 'd';
	}
	
	public function format_constant_text($text, $node) {
		if (!$this->inVarlistEntry || !$this->inTerm) {
			return;
		}
		$this->currentDefineInfo = $text;
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
	
	// will not use this because we want to render all functions in 1 file
	public function parseFunction() {}
}

/*
* vim600: sw=4 ts=4 syntax=php et
* vim<600: sw=4 ts=4
*/
