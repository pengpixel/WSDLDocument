<?php

namespace wsdldocument;

use DOMDocument;
use DOMElement;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * WSDL document generator.
 *
 * @author  Renan de Lima <renandelima@gmail.com>
 * @author  Harald Bongen <bongen@fh-aachen.de>
 *
 * @version 1.0.0
 */
class WSDLDocument extends DOMDocument
{

    const BINDING = 'http://schemas.xmlsoap.org/soap/http';
    const NS_SOAP_ENC = 'http://schemas.xmlsoap.org/soap/encoding/';
    const NS_SOAP_ENV = 'http://schemas.xmlsoap.org/wsdl/soap/';
    const NS_WSDL = 'http://schemas.xmlsoap.org/wsdl/';
    const NS_XML = 'http://www.w3.org/2000/xmlns/';
    const NS_XSD = 'http://www.w3.org/2001/XMLSchema';
    /**#@-*/

    /**
     * List of types already created or in creating process. It avoids
     * recursion when creating complex types that reference themselves. See
     * {@link WSDLDocument::createComplexType()} and
     * {@link WSDLDocument::createArrayType()} operations for more details.
     *
     * @var string[]
     */
    protected $aCreatedTypes = array();

    /**
     * @var DOMElement
     */
    protected $oBinding;

    /**
     * @var ReflectionClass
     */
    protected $oClass;

    /**
     * @var DOMElement
     */
    protected $oDefinitions;

    /**
     * @var DOMElement
     */
    protected $oPortType;

    /**
     * @var DOMElement
     */
    protected $oSchema;

    /**
     * @var string
     */
    protected $sTns;

    /**
     * @var string
     */
    protected $sUrl;

    /**
     * Set webservice and url class and target name space.
     *
     * @param string $sClass
     * @param null|string $sUrl
     * @param null|string $sTns
     * @throws ReflectionException|WSDLDocumentException
     */
    public function __construct(string $sClass, ?string $sUrl = null, ?string $sTns = null)
    {
        parent::__construct('1.0', 'utf-8');
        // set class, url and target namespace
        $this->oClass = new ReflectionClass($sClass);
        $this->sUrl = empty($sUrl) ? $this->getDefaultUrl() : $sUrl;
        $this->sTns = empty($sTns) ? $_SERVER['SERVER_NAME'] : $sTns;
        // create document
        $this->run();
    }

    /**
     * Create the WSDL definitions.
     *
     * @return void
     * @throws WSDLDocumentException
     */
    protected function run(): void
    {
        // create root, schema type, port type and binding tags
        $this->createMajorElements();
        // add methods
        foreach ($this->oClass->getMethods() as $oMethod) {
            $sComment = $oMethod->getDocComment();
            $ignored = !empty($this->getTagComment($sComment, 'ignoreInWsdl'));
            // check if method is allowed
            if (
                $oMethod->isPublic() && // it must be public and...
                !$oMethod->isStatic() && // non static
                !$oMethod->isConstructor() && // non constructor
                !$ignored && // not ignored by Annotation @ignoreInWsdl
                substr($oMethod->name, 0, 2) != '__' // non magic methods
            ) {
                // attach operation
                $this->createPortTypeOperation($oMethod);
                $this->createBindingOperation($oMethod);
                $this->createMessage($oMethod);
            }
        }
        // append port type and binding
        $this->oDefinitions->appendChild($this->oPortType);
        $this->oDefinitions->appendChild($this->oBinding);
        // service
        $this->createService();
    }

    /**
     * Create array type once. It doesn't create a type, you have to call
     * {@link WSDLDocument::createType()} to this. Returns the array type name.
     *
     * @param string $sType
     * @return string
     */
    protected function createArrayType(string $sType): string
    {
        // check if it was created
        $sName = $sType . 'Array';
        if (array_key_exists($sType, $this->aCreatedTypes)) {
            return $sName;
        }
        // avoid recursion
        $this->aCreatedTypes[$sType] = true;
        // create tags
        $oType = $this->createElementNS(self::NS_XSD, 'complexType');
        $this->oSchema->appendChild($oType);
        $oContent = $this->createElementNS(self::NS_XSD, 'complexContent');
        $oType->appendChild($oContent);
        $oRestriction = $this->createElementNS(self::NS_XSD, 'restriction');
        $oContent->appendChild($oRestriction);
        $oAttribute = $this->createElementNS(self::NS_XSD, 'attribute');
        $oRestriction->appendChild($oAttribute);
        // configure tags
        $oType->setAttribute('name', $sName);
        $oRestriction->setAttribute('base', 'soap-enc:Array');
        $oAttribute->setAttribute('ref', 'soap-enc:arrayType');
        // build name
        $sNamespace = $this->getTypeNamespace($sType);
        $sArrayType = $sNamespace . ':' . $sType . '[]';
        $oAttribute->setAttributeNS(self::NS_WSDL, 'arrayType', $sArrayType);
        return $sName;
    }

    /**
     * Create a binding operation tag.
     *
     * @param ReflectionMethod $oMethod
     * @return void
     */
    protected function createBindingOperation(ReflectionMethod $oMethod): void
    {
        $oOperation = $this->createElementNS(self::NS_WSDL, 'operation');
        $oOperation->setAttribute('name', $oMethod->name);
        $this->oBinding->appendChild($oOperation);
        // binding operation soap
        $oOperationSoap = $this->createElementNS(self::NS_SOAP_ENV, 'operation');
        $sSeparator = parse_url($this->sUrl, PHP_URL_QUERY) == '' ? '?' : '&';
        $sActionUrl = $this->sUrl . $sSeparator . 'method=' . $oMethod->name;
        $oOperationSoap->setAttribute('soapAction', $sActionUrl);
        $oOperationSoap->setAttribute('style', 'rpc');
        $oOperation->appendChild($oOperationSoap);
        // binding input and output
        foreach (array('input', 'output') as $sTag) {
            $oBindingTag = $this->createElementNS(self::NS_WSDL, $sTag);
            $oOperation->appendChild($oBindingTag);
            $oBody = $this->createElementNS(self::NS_SOAP_ENV, 'body');
            $oBody->setAttribute('use', 'encoded');
            $oBody->setAttribute('encodingStyle', self::NS_SOAP_ENC);
            $oBindingTag->appendChild($oBody);
        }
    }

    /**
     * Create a complex type once. It doesn't create a type, you have to call
     * {@link WSDLDocument::createType()} to this.
     *
     * @param $sClass
     * @return void
     * @throws WSDLDocumentException
     */
    protected function createComplexType($sClass): void
    {
        // check if it was created
        if (array_key_exists($sClass, $this->aCreatedTypes)) {
            return;
        }
        // avoid recursion
        $this->aCreatedTypes[$sClass] = true;
        // start type creation
        $oComplex = $this->createElementNS(self::NS_XSD, 'complexType');
        $this->oSchema->appendChild($oComplex);
        $oComplex->setAttribute('name', $sClass);
        $oAll = $this->createElementNS(self::NS_XSD, 'all');
        $oComplex->appendChild($oAll);
        // create attributes
        $oReflection = new ReflectionClass($sClass);
        foreach ($oReflection->getProperties() as $oProperty) {
            // check if property is allowed
            if (
                $oProperty->isPublic() === true && // it must be public and...
                $oProperty->isStatic() === false // non static
            ) {
                // create type for each element
                $sComment = $oProperty->getDocComment();
                $tagComment = $this->getTagComment($sComment, 'var');
                $sType = reset($tagComment);
                $sPropertyTypeId = $this->createType($sType);
                // create element of property
                $oElement = $this->createElementNS(self::NS_XSD, 'element');
                $oAll->appendChild($oElement);
                $oElement->setAttribute('name', $oProperty->name);
                $oElement->setAttribute('type', $sPropertyTypeId);
                $oElement->setAttribute('minOccurs', 0);
                $oElement->setAttribute('maxOccurs', 1);
            }
        }
    }

    /**
     * Create a message with operation arguments and return.
     *
     * @param ReflectionMethod $oMethod
     * @return void
     * @throws WSDLDocumentException
     */
    protected function createMessage(ReflectionMethod $oMethod): void
    {
        $sComment = $oMethod->getDocComment();
        // message input
        $oInput = $this->createElementNS(self::NS_WSDL, 'message');
        $oInput->setAttribute('name', $oMethod->name . 'Request');
        $this->oDefinitions->appendChild($oInput);
        // input part
        $aType = $this->getTagComment($sComment, 'param');
        $aParameter = $oMethod->getParameters();
        if (count($aType) != count($aParameter)) {
            $sMethodName = $this->oClass->getName() . '::' . $oMethod->getName() . '()';
            throw new WSDLDocumentException(
                'Declared and documented arguments do not match in ' . $sMethodName);
        }
        foreach ($aType as $iKey => $sType) {
            $oPart = $this->createElementNS(self::NS_WSDL, 'part');
            $oPart->setAttribute('name', $aParameter[$iKey]->name);
            $oPart->setAttribute('type', $this->createType($sType));
            $oInput->appendChild($oPart);
        }
        // message output
        $oOutput = $this->createElementNS(self::NS_WSDL, 'message');
        $oOutput->setAttribute('name', $oMethod->name . 'Response');
        $this->oDefinitions->appendChild($oOutput);
        // output part
        $tagComment = $this->getTagComment($sComment, 'return');
        $sType = (string)reset($tagComment);
        if ($sType != 'void' && $sType != '') {
            $oPart = $this->createElementNS(self::NS_WSDL, 'part');
            $oPart->setAttribute('name', $oMethod->name . 'Return');
            $oPart->setAttribute('type', $this->createType($sType));
            $oOutput->appendChild($oPart);
        }
    }

    /**
     * Create a port type operation tag.
     *
     * @param ReflectionMethod $oMethod
     * @return void
     */
    protected function createPortTypeOperation(ReflectionMethod $oMethod): void
    {
        $oOperation = $this->createElementNS(self::NS_WSDL, 'operation');
        $oOperation->setAttribute('name', $oMethod->name);
        $this->oPortType->appendChild($oOperation);
        // documentation
        $sDoc = $this->getDocComment($oMethod->getDocComment());
        $oDoc = $this->createElementNS(self::NS_WSDL, 'documentation', $sDoc);
        $oOperation->appendChild($oDoc);
        // input
        $oInput = $this->createElementNS(self::NS_WSDL, 'input');
        $oInput->setAttribute('message', 'tns:' . $oMethod->name . 'Request');
        $oOperation->appendChild($oInput);
        // output
        $oOutput = $this->createElementNS(self::NS_WSDL, 'output');
        $oOutput->setAttribute('message', 'tns:' . $oMethod->name . 'Response');
        $oOperation->appendChild($oOutput);
    }

    /**
     * Create service tag.
     *
     * @return void
     */
    protected function createService(): void
    {
        $oService = $this->createElementNS(self::NS_WSDL, 'service');
        $oService->setAttribute('name', $this->oClass->name);
        $this->oDefinitions->appendChild($oService);
        // documentation
        $sDoc = $this->getDocComment($this->oClass->getDocComment());
        $oDoc = $this->createElementNS(self::NS_WSDL, 'documentation', $sDoc);
        $oService->appendChild($oDoc);
        // port
        $oPort = $this->createElementNS(self::NS_WSDL, 'port');
        $oPort->setAttribute('name', $this->oClass->name . 'Port');
        $oPort->setAttribute('binding', 'tns:' . $this->oClass->name . 'Binding');
        $oService->appendChild($oPort);
        // address
        $oAddress = $this->createElementNS(self::NS_SOAP_ENV, 'address');
        $oAddress->setAttribute('location', $this->sUrl);
        $oPort->appendChild($oAddress);
    }

    /**
     * Create a type in document. Receive the raw type name as it was on
     * programmer's documentation. It returns wsdl name type.
     *
     * @param string $sType
     * @return string
     * @throws WSDLDocumentException
     */
    protected function createType(string $sType): string
    {
        // check if is array
        $sType = trim($sType);
        if ($sType == '') {
            throw new WSDLDocumentException('Invalid type.');
        }
        // check if it's array and its depth
        $iArrayDepth = 0;
        while (substr($sType, -2) == '[]') {
            $iArrayDepth++;
            $sType = substr($sType, 0, -2);
        }
        // create complex type if necessary
        $sType = $this->getType($sType);
        $sNamespace = $this->getTypeNamespace($sType);
        if ($sNamespace == 'tns') {
            $this->createComplexType($sType);
        }
        // create the arrays concerned depth
        if ($iArrayDepth > 0) {
            // force namespace to complex type, because it's an array
            $sNamespace = 'tns';
            for (; $iArrayDepth > 0; $iArrayDepth--) {
                $sType = $this->createArrayType($sType);
            }
        }
        // wsdl type name
        return $sNamespace . ':' . $sType;
    }

    /**
     * Generate default URL for SOAP requests.
     *
     * @return string
     */
    protected function getDefaultUrl(): string
    {
        // protocol
        $sProtocol = $this->getServer('HTTPS') == 'on' ? 'https' : 'http';
        // host and port
        $sHost = $this->getServer('HTTP_HOST');
        if ($sHost == '') {
            $sHost = $this->getServer('SERVER_NAME');
            $sPort = $this->getServer('SERVER_PORT');
            if ($sPort != '') {
                $sHost .= ':' . $sPort;
            }
        }
        // uri
        $sUri = $this->getServer('HTTP_X_REWRITE_URL');
        if ($sUri == '') {
            $sUri = $this->getServer('REQUEST_URI');
            if ($sUri == '') {
                $sUri = $this->getServer('ORIG_PATH_INFO');
                if ($sUri == '') {
                    $sUri = $this->getServer('SCRIPT_NAME');
                }
            }
        }
        if (($iLast = strpos($sUri, '?')) !== false) {
            $sUri = substr($sUri, 0, $iLast);
        }
        // return everything gathered
        return $sProtocol . '://' . $sHost . $sUri;
    }

    /**
     * @param string
     * @return string
     */
    protected function getServer(string $sKey): string
    {
        return $_SERVER[$sKey] ?? '';
    }

    /**
     * Fetch documentation in a comment.
     *
     * @param string $sComment
     * @return string
     */
    protected function getDocComment(string $sComment): string
    {
        $sValue = '';
        foreach (explode("\n", $sComment) as $sLine) {
            $sLine = trim($sLine, " *\t\r/");
            if (strlen($sLine) > 0 && $sLine{0} == '@') {
                break;
            }
            $sValue .= ' ' . $sLine;
        }
        return trim($sValue);
    }

    /**
     * Fetch tag's values from a comment.
     *
     * @param string $sComment
     * @param string $sTagName
     * @return string[]
     */
    protected function getTagComment(string $sComment, string $sTagName): array
    {
        $aValue = array();
        foreach (explode("\n", $sComment) as $sLine) {
            $sPattern = "/^\*\s+@(.[^\s]+)\s*([\S]*[^\s])?/";
            $sText = trim($sLine);
            $aMatch = array();
            preg_match($sPattern, $sText, $aMatch);
            if (count($aMatch) >= 2 && $aMatch[1] == $sTagName) {
                //pushes value after annotation or true
                array_push($aValue, array_key_exists(2, $aMatch) ? $aMatch[2] : true);
            }
        }
        return $aValue;
    }

    /**
     * Create basics tags. Definitions (root), schema (contain types), port type
     * and binding.
     *
     * @return void
     */
    protected function createMajorElements()
    {
        // >> definitions
        $this->oDefinitions = $this->createElementNS(self::NS_WSDL, 'wsdl:definitions');
        $this->oDefinitions->setAttributeNS(self::NS_XML, 'xmlns:soap-enc', self::NS_SOAP_ENC);
        $this->oDefinitions->setAttributeNS(self::NS_XML, 'xmlns:soap-env', self::NS_SOAP_ENV);
        $this->oDefinitions->setAttributeNS(self::NS_XML, 'xmlns:tns', $this->sTns);
        $this->oDefinitions->setAttributeNS(self::NS_XML, 'xmlns:wsdl', self::NS_WSDL);
        $this->oDefinitions->setAttributeNS(self::NS_XML, 'xmlns:xsd', self::NS_XSD);
        $this->oDefinitions->setAttribute('targetNamespace', $this->sTns);
        $this->appendChild($this->oDefinitions);
        // >> definitions >> types
        $oTypes = $this->createElementNS(self::NS_WSDL, 'types');
        $this->oDefinitions->appendChild($oTypes);
        // >> definitions >> types >> schema
        $this->oSchema = $this->createElementNS(self::NS_XSD, 'schema');
        $this->oSchema->setAttribute('targetNamespace', $this->sTns);
        $oTypes->appendChild($this->oSchema);
        // >> definitions >> types >>port type
        $this->oPortType = $this->createElementNS(self::NS_WSDL, 'portType');
        $this->oPortType->setAttribute('name', $this->oClass->name . 'PortType');
        // >> definitions >> types >> binding
        $this->oBinding = $this->createElementNS(self::NS_WSDL, 'binding');
        $this->oBinding->setAttribute('name', $this->oClass->name . 'Binding');
        $this->oBinding->setAttribute('type', 'tns:' . $this->oClass->name . 'PortType');
        // >> definitions >> types >> binding >> soap binding
        $oBindingSoap = $this->createElementNS(self::NS_SOAP_ENV, 'binding');
        $oBindingSoap->setAttribute('style', 'rpc');
        $oBindingSoap->setAttribute('transport', self::BINDING);
        $this->oBinding->appendChild($oBindingSoap);
    }

    /**
     * Get type name.
     *
     * @param string $sType
     * @return string
     */
    protected function getType(string $sType): string
    {
        switch ($sType) {
            case 'array':
            case 'struct':
                $type = 'array';
                break;
            case 'boolean':
            case 'bool':
                $type = 'boolean';
                break;
            case 'double':
            case 'float':
            case 'real':
                $type = 'float';
                break;
            case 'integer':
            case 'int':
                $type = 'int';
                break;
            case 'string':
            case 'str':
                $type = 'string';
                break;
            default:
                $type = $sType;
                break;
        }
        return $type;
    }

    /**
     * Get type namespace.
     *
     * @param string $sType
     * @return string
     */
    protected function getTypeNamespace(string $sType): string
    {
        switch ($sType) {
            case 'array':
            case 'struct':
                $namespace = 'soap-enc';
                break;
            case 'boolean':
            case 'bool':
            case 'double':
            case 'float':
            case 'real':
            case 'integer':
            case 'int':
            case 'string':
            case 'str':
                $namespace = 'xsd';
                break;
            // complex type, everything else is native type
            default:
                $namespace = 'tns';
                break;
        }
        return $namespace;
    }
}
