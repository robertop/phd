<?php
namespace phpdotnet\phd;
/* $Id$ */

/**
 * This class will render a CTags file containing all of the classes, functions, defines that
 * come in PHP.
 * 
 * The following tag kinds will be generated:
 * c   a class or interface tag
 *     It may have extension field 'i' for the inheritance info (base class, interfaces implemented)
 *     It may have extension field 'a' that will hold the 'final' if class is final
 *     It may have extension field 'm' that will hold the 'abstract' if class is abstract
 * f   a function or class method tag
 *     It may have extension field 'class' that has the name of the class the method is in (method tags only)
 *     It may have extension field 'S' that will have the function's signature. The return type of a method will be
 *     the first word in the signature , for example
 *     string function substr($string, $start, $length)    <-- for a function
 *     string function method($arg1, $arg2)                <-- for a method, it will NOT have any modifiers (private, static,...)
 *
 *     It may have extension field 'a' that will hold the 'static', 'private', or 'protected' or 'final' modifiers (none means public)
 *     It may have extension field 'm' that will hold the 'abstract' if method is abstract
 * d   a predefined constant tag 
 * p   a class member variable tag 
 *     It will have extension field 'class' that has the name of the class the property is in
 *     It may have extension field 'a' that will hold the 'static', 'private', or 'protected' or 'final' modifiers (none means public)
 * o   a class constant tag 
 *     It will have extension field 'class' that has the name of the class the constant is in
 *
 * Kinds p,k deviate from the kinds provided by the default ctags PHP implementation and will most likely
 * not be accessible by most editors.
 *
 * None of the tags will have an ex_cmd (or rather a "0" will be put in its place), since there is no source 
 * file that can be jumped to.
 *
 * None of the tags will have a file name (empty strign) since there is no source file that can be jumped to.
 *
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
	 * The captured info for a class member variable.
	 */
	private $currentPropertyInfo;
	
	/**
	 * The modifiers for a function (protected, static, final). we capture these here because
	 * the base class does not.
	 * This works a bit different than property modifiers, this array will be a map
	 * of all modifiers of all methods of a class. For example:
	 * array('getMessage' => array('protected', 'final'), 'getCode' => array('protected', 'final'))
	 */
	private $functionModifiers;
	
	/**
	 * we want to capture both function name and the modifiers; since they are in adjacent tags
	 * we need to the modifiers when we see a modifier, the save it into the funcitonModifiers list
	 * once we read the function name node.
	 */
	private $currentFunctionModifiers;
	
	/**
	 * This will keep track of which nodes are currently being iterated through.
	 * Each record in this map will have the XML tag name as its key and
	 * the value is a boolean value where TRUE means that currently
	 * the tag is open. For example, if 'classsynopsisinfo' and 'classname'
	 * tags are both set to TRUE it means that we are currently iterating
	 * inside of a classname tag which is inside of a classsynopsisinfo tag.
	 * Using this map we can easily check that we are in a relevant
	 * node in the hierachy.
	 */
	private $openNodes = array(
	
		// tags that hold classes
		'classsynopsisinfo' => FALSE,
		'classname' => FALSE,
		'ooclass' => FALSE,
		'modifier' => FALSE,
		
		// tags that hold constants
		'appendix' => FALSE,
		'varlistentry' => FALSE,
		'term' => FALSE,
		
		// tags that hold class variables (properties)
		'fieldsynopsis' => FALSE
		
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
			'classname' => FALSE,
			'ooclass' => FALSE,
			'modifier' => FALSE,
			'appendix' => FALSE,
			'varlistentry' => FALSE,
			'term' => FALSE,
			'fieldsynopsis' => FALSE
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
		// 
		// <appendix xmlns="http://docbook.org/ns/docbook" xml:id="id3.constants">
		// <title>Predefined Constants</title>
		// <para>
		//  ...
		// <variablelist>
		// <varlistentry xml:id="constant.id3-v1-0">
		// <term> <constant>ID3_V1_0</constant> (<type>integer</type>) </term>
		// ...
		// </varlistentry>
		$this->elementmap['appendix'] = 'format_appendix';
		$this->elementmap['term'] = 'format_term';
		$this->elementmap['varlistentry'] = 'format_varlistentry';
		$this->textmap['constant'] = 'format_constant_text';
		
		// nodes that contain class properties. note: modifier tag already handled above
		// <fieldsynopsis>
		// <modifier>protected</modifier>
		// <type>string</type>
		// <varname linkend="exception.props.message">message</varname>
		// </fieldsynopsis>
		$this->elementmap['fieldsynopsis'] = 'format_fieldsynopsis';
		$this->textmap['varname'] = 'format_varname_text';
		
		// the method modifiers
		$this->elementmap['methodsynopsis'] = 'format_methodsynopsis';
		$this->textmap['methodname'] = 'format_methodname_text';
		
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
				$this->functionModifiers = array();
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
		if (!$this->areNodesOpen('classsynopsisinfo', 'ooclass') && !$this->areNodesOpen('fieldsynopsis') && !$this->areNodesOpen('methodsynopsis')) {
			return;
		}
		if ($this->areNodesOpen('classsynopsisinfo', 'ooclass')) {
			$this->openNode('modifier', strcasecmp($value, 'extends') == 0);
		}
		else if ($this->areNodesOpen('fieldsynopsis')) {
			if (strcasecmp($value, 'protected') == 0) {
				$this->currentPropertyInfo['protected'] = TRUE;
			}
			else if (strcasecmp($value, 'static') == 0) {
				$this->currentPropertyInfo['static'] = TRUE;
			}
			else if (strcasecmp($value, 'final') == 0) {
				$this->currentPropertyInfo['final'] = TRUE;
			}
		}
		else if ($this->areNodesOpen('methodsynopsis') && strcasecmp($value, 'public') != 0) {
		
			// everything is public by default, no need to show it in the tag file.
			$this->currentFunctionModifiers[] =  $value;
		}
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
		if (!$this->areNodesOpen('appendix')) {
			return;
		}
		$this->openNode('varlistentry', $open);
		if ($open) {
			$this->currentDefineInfo = '';
			return;
		}
		 
		if ($this->currentDefineInfo) {
			$data = $this->renderDefine();
			
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
			$tag = $defineName . "\t" . '' . "\t" . $signature . "\t" . 'o' . "\tclass:" . $className;
		}
		return $tag;
	}
	
	public function format_varname_text($text, $node) {
		if (!$this->areNodesOpen('fieldsynopsis')) {
			return;
		}
		$this->currentPropertyInfo['name'] = $text;
	}
	
	public function format_fieldsynopsis($open, $name, $attrs, $props) {
		$this->openNode('fieldsynopsis', $open);
		if ($open) {
			$this->currentPropertyInfo = array(
				'name' => '',
				'protected' => FALSE,
				'static' => FALSE,
				'final' => FALSE
			);
		}
		else {
			$data = $this->renderProperty();
			
			// guarantee only 1 newline
			$data = trim($data);
			fwrite($this->tagFile, $data);
			fwrite($this->tagFile, "\n");
		}
	}
	
	private function renderProperty() {
		$className = $this->currentClassInfo['name'];
		$propertyName = $this->currentPropertyInfo['name'];
		$signature = '/^$' . $propertyName . '/;"';
		$tag = $propertyName . "\t" . '' . "\t" . $signature. "\t" . 'p' . "\t" . 'class:' . $className;
		$access = array();
		if ($this->currentPropertyInfo['protected']) {
			$access[] = 'protected';
		}
		if ($this->currentPropertyInfo['static']) {
			$access[] = 'static';
		}
		if ($this->currentPropertyInfo['final']) {
			$access[] = 'final';
		}
		if ($access) {
			$tag .= "\t" . 'a:' . join(',', $access);
		}
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
			$this->currentFunctionModifiers = array();

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
	
	public function format_methodsynopsis($open, $name, $attrs, $props) {
		$this->openNode('methodsynopsis', $open);
	}
	
	public function format_methodname_text($text, $node) {
		if (!$this->areNodesOpen('methodsynopsis')) {
			return;
		}
		$this->functionModifiers[$this->toValidName($text)] = $this->currentFunctionModifiers;
	}
	
    private function renderFunction() {
		$name = trim($this->function['name']);
		$isMethod = FALSE;
		$className = '';
		$dotIndex = stripos($name, '.');
		if ($dotIndex !== FALSE) {
		
			// this is a method. parse the class name out of it
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
			
			// map is indexed by full method name (class '.' method)
			$allModifiers = isset($this->functionModifiers[$this->function['name']]) ? $this->functionModifiers[$this->function['name']] : NULL;
			if ($allModifiers) {
				$accessModifiers = $allModifiers;
				$index = array_search('abstract', $accessModifiers);
				if ($index !== FALSE) {
					unset($accessModifiers[$index]);
				}
				if ($accessModifiers) {
					$str .= "\t";
					$str .= 'a:';
					$str .= join(',', $accessModifiers);
				}
				if ($index !== FALSE) {
					$str .= "\t";
					$str .= 'm: abstract';
				}
			}
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
