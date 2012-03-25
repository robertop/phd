<?php
namespace phpdotnet\phd;
/* $Id$ */

/**
 * This class will render a CTags file containing all of the classes, functions, defines that
 * come in PHP.
 * 
 * The following tag kinds will be generated:
 * c   a class or interface it will have an extension field 'i' for the inheritance info (base class, interfaces implemented)
 * f   a function or class method, a method will have an extension field 'class' that has the name of the class
 *     the method is in.
 * d   a predefined constant
 * p   a class member variable. Will have an extension field 'class' that has the 
 *     name of the class the property / constant is in
 * k   a class constant. Will have an extension field 'class' that has the 
 *     name of the class the property / constant is in
 *
 * Kinds p,k deviate from the kinds provided by the default ctags PHP implementation and will most likely
 * not be accessible by most editors.
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
	 * The captured info for a class member variable
	 */
	private $currentPropertyInfo;
	
	/**
	 * This will keep track of which nodes are currently being iterated through.
	 * Each record in this map will have the XML tag name as its key and
	 * the value is a boolean value where TRUE means that currently
	 * the tag is open. For example, if 'classsynopsis' and 'classname'
	 * tags are both set to TRUE it means that we are currently iterating
	 * inside of a classname tag which is inside of a classsynopsis tag.
	 * Using this map we can easily check that we are in a relevant
	 * node in the hierachy.
	 */
	private $openNodes = array(
	
		// tags that hold classes
		'classsynopsis' => FALSE,
		'classname' => FALSE,
		'ooclass' => FALSE,
		'modifier' => FALSE,
		
		// tags that hold constants
		'appendix' => FALSE,
		'varlistentry' => FALSE,
		'term' => FALSE,
		
		// tags that hold class variables (properties)
		'section' => FALSE,
		'variablelist' => FALSE,
		
	);
	
	public function __construct() {
        $this->registerFormatName('CTags');
        $this->setExt(Config::ext() === null ? ".php" : Config::ext());
		$this->tagFile = NULL;
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
		$this->openNodes = array(
			'classsynopsisinfo' => FALSE,
			'ooclass' => FALSE,
			'classname' => FALSE,
			'modifier' => FALSE,
			'appendix' => FALSE,
			'varlistentry' => FALSE,
			'term' => FALSE,
			'section' => FALSE,
			'variablelist' => FALSE
		);
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
		
		// nodes that contain class properties
		$this->elementmap['section'] = 'format_section';
		$this->elementmap['variablelist'] = 'format_variablelist';
		$this->textmap['varname'] = 'format_varname_text';
		
		parent::STANDALONE($value);
	}
	
	/**
	 * captures the class name when traversing the classsynopsisinfo node
	 */
	public function format_classsynopsisinfo($open, $name, $attrs, $props) {
		$this->openNode('classsynopsisinfo', $open);
		$this->closeNodes('ooclass', 'classname', 'modifier');
		$hasRole = isset($attrs[Reader::XMLNS_DOCBOOK]['role']) && strlen($attrs[Reader::XMLNS_DOCBOOK]['role']) > 0;
		if ($open) {			
			if (!$hasRole) { 
			
				// clear out any previous info whe the class tag is opened
				// but only clear it in the class tag and not the comment sections
				// because the property tags need the class name
				$this->currentClassInfo = array(
					'modifier' => '',
					'name' => '',
					'extends' => '',
					'implements' => array()
				);
			}
			return;
		}
		
		// only handle the classsynopsisinfo tag that does not have a role
		if ($hasRole) {
			return;
		}
		$data = $this->renderClass();
		
		// guarantee only 1 newline
		$data = trim($data);
		fwrite($this->tagFile, $data);
		fwrite($this->tagFile, "\n");
	}
	
	public function format_ooclass($open, $name, $attrs, $props) {
		$this->openNode('ooclass', $open);
	}
	
	public function format_classname_text($value, $tag) {
		if (!$this->areNodesOpen('classsynopsisinfo', 'ooclass')) {
			return;
		}
		if ($this->areNodesOpen('modifier')) {
			$this->currentClassInfo['extends'] = $value;
		}
		else {
			$this->currentClassInfo['name'] = $value;
		}
	}
	
	public function format_modifier_text($value, $tag) {
		if (!$this->areNodesOpen('classsynopsisinfo', 'ooclass')) {
			return;
		}
		$this->openNode('modifier', strcasecmp($value, 'extends') == 0);
	}
	
	public function format_interfacename_text($value, $tag) {
		if (!$this->areNodesOpen('classsynopsisinfo', 'ooclass')) {
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
		$this->openNode('appendix', $open);
		$this->closeNodes('varlistentry', 'term');
	}
	
	public function format_varlistentry($open, $name, $attrs, $props) {
		if (!$this->areNodesOpen('appendix', 'variablelist') && !$this->areNodesOpen('section', 'variablelist')) {
			return;
		}
		$this->openNode('varlistentry', $open);
		if ($open) {
			$this->currentDefineInfo = '';
			$this->currentPropertyInfo = '';
			return;
		}
		 
		if ($this->currentDefineInfo && $this->areNodesOpen('appendix')) {
			$data = $this->renderDefine();
			
			// guarantee only 1 newline
			$data = trim($data);
			fwrite($this->tagFile, $data);
			fwrite($this->tagFile, "\n");
		}
		else if ($this->areNodesOpen('section')) {
			$data = $this->renderProperty();
			
			// guarantee only 1 newline
			$data = trim($data);
			fwrite($this->tagFile, $data);
			fwrite($this->tagFile, "\n");
		}
	}
	
	public function format_term($open, $name, $attrs, $props) {
		$this->openNode('term', $open);
	}
	
	public function format_constant_text($text, $node) {
		if (!$this->areNodesOpen('appendix', 'varlistentry', 'term')) {
			v("appendix open? " . $this->openNodes['appendix'] . ' ' . $this->openNodes['varlistentry'] . ' ' . $this->openNodes['term'], VERBOSE_INFO);
			return;
		}
		$this->currentDefineInfo = $text;
	}
	
	private function renderDefine() {
	
		// class constants also commented using the constant tag, but they contain
		// the scope resolution operator. output the proper tag based on
		// whether the constant is a class constant or not.
		$indexScopeResolution = stripos($this->currentDefineInfo, '::');
		$className = '';
		$defineName = $this->currentDefineInfo;
		$signature = "/^define('{$defineName}', '')/;\"";
		$tag = $defineName . "\t" . '' . "\t" . $signature . "\t" . 'd';
		if ($indexScopeResolution !== FALSE) {
			$className = substr($defineName, 0, $indexScopeResolution);
			$defineName = substr($defineName, $indexScopeResolution + 2); // 2 = skip the'::'
			$signature = "/^const {$defineName}/;\"";
			$tag = $defineName . "\t" . '' . "\t" . $signature . "\t" . 'k' . "\tclass:" . $className;
		}
		return $tag;
	}
	
	public function format_section($open, $name, $attrs, $props) {
		$this->openNode('section', $open && 
			isset($attrs[Reader::XMLNS_XML]) && 
			isset($attrs[Reader::XMLNS_XML]['id']) && 
			substr($attrs[Reader::XMLNS_XML]['id'], -6) == '.props');
	}
	
	public function format_variablelist($open, $name, $attrs, $props) {
		$this->openNode('variablelist', $open);
	}
	
	public function format_varname_text($text, $node) {
		if (!$this->areNodesOpen('section', 'variablelist', 'varlistentry')) {
			return;
		}
		$this->currentPropertyInfo = $text;
	}
	
	private function renderProperty() {
		$className = $this->currentClassInfo['name'];
		$propertyName = $this->currentPropertyInfo;
		$signature = '/^$' . $propertyName . '/;"';
		$tag = $propertyName . "\t" . '' . "\t" . $signature. "\t" . 'p' . "\t" . 'class:' . $className;
		return $tag;
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
			$str .= 'f';
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
	
	/**
	 * @param $nodes,... varargs of strings, one of each node to check
	 * @return boolean TRUE if ALL of the given nodes are currently open
	 */
	private function areNodesOpen() {
		$nodes = func_get_args();
		$allOpen = count($nodes) > 0;
		foreach ($nodes as $node) {
			if (!$allOpen) {
				break;
			}
			$allOpen = $this->openNodes[$node];
		}
		return $allOpen;
	}
	
	/**
	 * sets the open flag to FALSE on all of the given nodes
	 *
	 * @param $nodes,... varargs of strings, one of each node to close
	 */
	private function closeNodes($nodes) {
		$nodes = func_get_args();
		foreach ($nodes as $node) {
			$this->openNodes[$node] = FALSE;
		}
	}
	
	/**
	 * sets the open flag on all of the given nodes
	 * @param $node string nodename to set as open
	 * @param $flag TRUE of FALSE  the value to set the open flag to
	 */
	private function openNode($node, $isOpen) {
		$this->openNodes[$node] = $isOpen;
	}
	
	// will not use this because we want to render all functions in 1 file
	public function parseFunction() {}
}

/*
* vim600: sw=4 ts=4 syntax=php et
* vim<600: sw=4 ts=4
*/
