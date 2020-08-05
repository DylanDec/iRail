<?php

/**
 * An abstract class for a printer. It prints a document.
 */
abstract class Printer
{
    const PRIVATE_VAR_PREFIX = "_";
    protected $documentRoot;
    protected $root;
    private $hash;

    /**
     * @param $documentRoot
     */
    public function __construct($documentRoot)
    {
        $this->documentRoot = $documentRoot;
    }

    public function printAll()
    {
        // Only create this hash once
        $this->hash = get_object_vars($this->documentRoot);

        unset($this->hash['timestamp']);
        $etag = md5(json_encode($this->hash));

        // Print caching and etag headers
        $this->printCacheHeaders($etag);

        $headers = $this->getallheaders();
        if (key_exists(
            'If-None-Match',
            $headers
        ) && ($headers['If-None-Match'] == '"' . $etag . '"' || $headers['If-None-Match'] == 'W/"' . $etag . '"')) {
            // Print the unchanged response code. Don't transmit a body
            http_response_code("304");
            return;
        }

        $this->printHeader();
        $this->printBody();
    }

    /**
     * Use our own function to be compatible with nginx.
     * Source: http://php.net/manual/en/function.getallheaders.php
     *
     * @return array
     */
    private function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }

        return $headers;
    }

    /**
     * prints http header: what kind of output, etc.
     *
     * @param string format a mime type
     */
    abstract public function printHeader();

    /**
     * prints the body: The idea begind this is a reversed sax-parser. It will create events which you will have to implement in your implementation of an output.
     */
    public function printBody()
    {
        //so that people would know that we have a child of the rootelement
        $this->root = true;
        $this->startRootElement(
            strtolower($this->documentRoot->getRootname()),
            $this->documentRoot->version,
            $this->documentRoot->timestamp
        );

        $counter = 0;
        foreach ($this->hash as $key => $val) {
            if ($key == 'version' || $key == 'timestamp' || $this->isPrivateVariableName($key)) {
                $counter++;
                continue;
            }

            $this->printElement($key, $val, true);
            if ($counter < count($this->hash) - 1) {
                $this->nextObjectElement();
            }

            $counter++;
        }
        $this->endRootElement(strtolower($this->documentRoot->getRootname()));
    }

    /**
     * It will detect what kind of element the element is and will print it accordingly.
     * If it contains more elements it will print more recursively.
     *
     * @param      $key
     * @param      $val
     * @param bool $root
     * @throws Exception
     */
    private function printElement($key, $val, $root = false)
    {
        if (is_array($val)) {
            if (count($val) > 0) {
                $this->startArray($key, count($val), $root);
                $i = 0;
                foreach ($val as $elementval) {
                    $this->printElement($key, $elementval);
                    // Keep count of where we are, and as long as this isn't the last element, print the array divider
                    if ($i < (count($val) - 1)) {
                        $this->nextArrayElement();
                    }
                    $i++;
                }
                $this->endArray($key, $root);
            } else {
                //very dirty fix of the komma problem when empty array when this would occur
                $this->startKeyVal('empty', '');
                $this->endElement('empty');
            }
        } elseif (is_object($val)) {
            $this->startObject($key, $val);
            $hash = get_object_vars($val);
            $counter = 0;
            foreach ($hash as $elementkey => $elementval) {
                if ($this->isPrivateVariableName($elementkey)) {
                    // Don't print private var
                } else {
                    $this->printElement($elementkey, $elementval);
                    if ($counter < count($hash) - 1) {
                        $this->nextObjectElement();
                    }
                }
                $counter++;
            }
            $this->endObject($key);
        } elseif (is_bool($val)) {
            $val = $val ? 1 : 0; //turn boolean into an int
            $this->startKeyVal($key, $val);
            $this->endElement($key);
        } elseif (!is_null($val)) {
            $this->startKeyVal($key, $val);
            $this->endElement($key);
        } else {
            throw new Exception(
                'Could not retrieve the right information - please report this problem to iRail@list.iRail.be or try again with other arguments.',
                500
            );
        }
    }

    public function nextArrayElement()
    {
    }

    public function nextObjectElement()
    {
    }

    /**
     * @param $name
     * @param $version
     * @param $timestamp
     * @return mixed
     */
    abstract public function startRootElement($name, $version, $timestamp);

    /**
     * @param      $name
     * @param      $number
     * @param bool $root
     * @return mixed
     */
    abstract public function startArray($name, $number, $root = false);

    /**
     * @param $name
     * @param $object
     * @return mixed
     */
    abstract public function startObject($name, $object);

    /**
     * @param $key
     * @param $val
     * @return mixed
     */
    abstract public function startKeyVal($key, $val);

    /**
     * @param      $name
     * @param bool $root
     * @return mixed
     */
    abstract public function endArray($name, $root = false);

    /**
     * @param $name
     */
    public function endObject($name)
    {
        $this->endElement($name);
    }

    /**
     * @param $name
     * @return mixed
     */
    abstract public function endElement($name);

    /**
     * @param $name
     * @return mixed
     */
    abstract public function endRootElement($name);

    /**
     * @param $ec
     * @param $msg
     * @return mixed
     */
    abstract public function printError($ec, $msg);

    private function printCacheHeaders($etag)
    {
        header('ETag: "' . $etag . '"');
        header('Cache-Control: max-age=15');
    }

    /**
     * @param $elementkey
     * @return bool
     */
    private function isPrivateVariableName($elementkey): bool
    {
        return strpos($elementkey, self::PRIVATE_VAR_PREFIX) === 0;
    }
}
