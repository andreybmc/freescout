<?php
/*
* File: Header.php
* Category: -
* Author: M.Goldenbaum
* Created: 17.09.20 20:38
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;


use Carbon\Carbon;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;

/**
 * Class Header
 *
 * @package Webklex\PHPIMAP
 */
class Header {

    /**
     * Raw header
     *
     * @var string $raw
     */
    public $raw = "";

    /**
     * Attribute holder
     *
     * @var Attribute[]|array $attributes
     */
    protected $attributes = [];

    /**
     * Config holder
     *
     * @var array $config
     */
    protected $config = [];

    /**
     * Fallback Encoding
     *
     * @var string
     */
    public $fallback_encoding = 'UTF-8';

    /**
     * Convert parsed values to attributes
     *
     * @var bool
     */
    protected $attributize = false;

    /**
     * Header constructor.
     * @param string $raw_header
     * @param boolean $attributize
     *
     * @throws InvalidMessageDateException
     */
    public function __construct(string $raw_header, bool $attributize = true) {
        $this->raw = $raw_header;
        $this->config = ClientManager::get('options');
        $this->attributize = $attributize;
        $this->parse();
    }

    /**
     * Call dynamic attribute setter and getter methods
     * @param string $method
     * @param array $arguments
     *
     * @return Attribute|mixed
     * @throws MethodNotFoundException
     */
    public function __call(string $method, array $arguments) {
        if (strtolower(substr($method, 0, 3)) === 'get') {
            $name = preg_replace('/(.)(?=[A-Z])/u', '$1_', substr(strtolower($method), 3));

            if (in_array($name, array_keys($this->attributes))) {
                return $this->attributes[$name];
            }

        }

        throw new MethodNotFoundException("Method " . self::class . '::' . $method . '() is not supported');
    }

    /**
     * Magic getter
     * @param $name
     *
     * @return Attribute|null
     */
    public function __get($name) {
        return $this->get($name);
    }

    /**
     * Get a specific header attribute
     * @param $name
     *
     * @return Attribute|mixed
     */
    public function get($name) {
        if (isset($this->attributes[$name])) {
            return $this->attributes[$name];
        }

        return null;
    }

    /**
     * Set a specific attribute
     * @param string $name
     * @param array|mixed $value
     * @param boolean $strict
     *
     * @return Attribute
     */
    public function set(string $name, $value, bool $strict = false) {
        if (isset($this->attributes[$name]) && $strict === false) {
            if ($this->attributize) {
                $this->attributes[$name]->add($value, true);
            } else {
                if (isset($this->attributes[$name])) {
                    if (!is_array($this->attributes[$name])) {
                        $this->attributes[$name] = [$this->attributes[$name], $value];
                    } else {
                        $this->attributes[$name][] = $value;
                    }
                } else {
                    $this->attributes[$name] = $value;
                }
            }
        } elseif (!$this->attributize) {
            $this->attributes[$name] = $value;
        } else {
            $this->attributes[$name] = new Attribute($name, $value);
        }

        return $this->attributes[$name];
    }

    /**
     * Perform a regex match all on the raw header and return the first result
     * @param $pattern
     *
     * @return mixed|null
     */
    public function find($pattern) {
        if (preg_match_all($pattern, $this->raw, $matches)) {
            if (isset($matches[1])) {
                if (count($matches[1]) > 0) {
                    return $matches[1][0];
                }
            }
        }
        return null;
    }

    /**
     * Try to find a boundary if possible
     *
     * @return string|null
     */
    public function getBoundary() {
        $boundary = '';

        // Finding boundary via regex is not 100% reliable as boundary
        // may be mentioned in other headers.
        if (is_object($this->boundary)) {
            $values = $this->boundary->get();
            if (!empty($values[0])) {
                $boundary = $values[0];
            }
        }

        if (!$boundary) {
            // Regex-based boundary extraction
            $regex = $this->config["boundary"] ?? "/boundary=(.*?(?=;)|(.*))/i";
            $boundary = $this->find($regex);
        }

        if ($boundary) {
            $boundary = $this->decodeBoundary($boundary);
        }

        if ($boundary === null) {
            return null;
        }

        return $this->clearBoundaryString($boundary);
    }

    /**
     * // Decode the boundary if necessary (RFC 2231 encoding)
     * https://github.com/freescout-help-desk/freescout/issues/4567
     *
     * @return string|null
     */
    protected function decodeBoundary($boundary) {
        
        if (strpos($boundary, "'") !== false) {
            $parts = explode("'", $boundary, 3);
            if (count($parts) === 3) {
                $charset = $parts[0] ?? 'us-ascii';
                $language = $parts[1] ?? '';
                $encodedValue = $parts[2] ?? '';
                $new_boundary = rawurldecode($encodedValue);

                // Convert charset if necessary.
                if (function_exists('mb_convert_encoding') && strtolower($charset) !== 'utf-8') {
                    try {
                        $boundary = mb_convert_encoding($new_boundary, 'UTF-8', $charset);
                    } catch (\Exception $e) {
                        // Do nothing.
                    }
                } else {
                    $boundary = $new_boundary;
                }
            }
        }

        return $boundary;
    }

    /**
     * Remove all unwanted chars from a given boundary
     * @param string $str
     *
     * @return string
     */
    private function clearBoundaryString(string $str): string {
        return str_replace(['"', '\r', '\n', "\n", "\r", ";", "\s"], "", $str);
    }

    /**
     * Parse the raw headers
     *
     * @throws InvalidMessageDateException
     */
    protected function parse() {
        $header = self::rfc822_parse_headers($this->raw);

        $this->extractAddresses($header);

        if (property_exists($header, 'subject')) {
            //$this->set("subject", $this->decode($header->subject));
            $subject = \MailHelper::decodeSubject($header->subject);
            $this->set("subject", $subject);
        }
        if (property_exists($header, 'references')) {
            $this->set("references", $this->decode($header->references));
        }
        if (property_exists($header, 'message_id')) {
            $this->set("message_id", str_replace(['<', '>'], '', $header->message_id));
        }

        $this->parseDate($header);
        foreach ($header as $key => $value) {
            $key = trim(rtrim(strtolower($key)));
            if (!isset($this->attributes[$key])) {
                $this->set($key, $value);
            }
        }

        $this->extractHeaderExtensions();
        $this->findPriority();
    }

    /**
     * Parse mail headers from a string
     * @link https://php.net/manual/en/function.imap-rfc822-parse-headers.php
     * @param $raw_headers
     *
     * @return object
     */
    public static function rfc822_parse_headers($raw_headers) {
        $headers = [];
        $imap_headers = [];

        $raw_headers = $raw_headers ?? '';

        // Consider rfc822 option to be always 'true'.
        if (extension_loaded('imap') /*&& isset($this->config) && $this->config["rfc822"]*/) {
            $raw_imap_headers = (array)\imap_rfc822_parse_headers($raw_headers);
            foreach ($raw_imap_headers as $key => $values) {
                $key = str_replace("-", "_", $key);
                $values = self::sanitizeHeaderValue($values);
                if (!is_array($values) || (is_array($values) && count($values))) {
                    $imap_headers[$key] = $values;
                }
            }
        }
        $lines = explode("\r\n", preg_replace("/\r\n\s/", ' ', $raw_headers));
        $prev_header = null;
        foreach ($lines as $line) {
            if (substr($line, 0, 1) === "\n") {
                $line = substr($line, 1);
            }

            if (substr($line, 0, 1) === "\t") {
                $line = substr($line, 1);
                $line = trim(rtrim($line));
                if ($prev_header !== null) {
                    $headers[$prev_header][] = $line;
                }
            } elseif (substr($line, 0, 1) === " ") {
                $line = substr($line, 1);
                $line = trim(rtrim($line));
                if ($prev_header !== null) {
                    if (!isset($headers[$prev_header])) {
                        $headers[$prev_header] = "";
                    }
                    if (is_array($headers[$prev_header])) {
                        $headers[$prev_header][] = $line;
                    } else {
                        $headers[$prev_header] .= $line;
                    }
                }
            } else {
                if (($pos = strpos($line, ":")) > 0) {
                    $key = trim(rtrim(strtolower(substr($line, 0, $pos))));
                    $key = str_replace("-", "_", $key);

                    $value = trim(rtrim(substr($line, $pos + 1)));
                    if (isset($headers[$key])) {
                        $headers[$key][] = $value;
                    } else {
                        $headers[$key] = [$value];
                    }
                    $prev_header = $key;
                }
            }
        }

        foreach ($headers as $key => $values) {
            if (isset($imap_headers[$key])) continue;
            $value = null;
            switch ((string)$key) {
                case 'from':
                case 'to':
                case 'cc':
                case 'bcc':
                case 'reply_to':
                case 'sender':
                    $value = self::decodeAddresses($values);
                    $headers[$key . "address"] = implode(", ", $values);
                    break;
                case 'subject':
                    $value = implode(" ", $values);
                    break;
                default:
                    if (is_array($values)) {
                        foreach ($values as $k => $v) {
                            if ($v == "") {
                                unset($values[$k]);
                            }
                        }
                        $available_values = count($values);
                        if ($available_values === 1) {
                            $value = array_pop($values);
                        } elseif ($available_values === 2) {
                            $value = implode(" ", $values);
                        } elseif ($available_values > 2) {
                            $value = array_values($values);
                        } else {
                            $value = "";
                        }
                    }
                    break;
            }
            $value = self::sanitizeHeaderValue($value);
            if (!is_array($value) || (is_array($value) && count($value))) {
                $headers[$key] = $value;
            } elseif (is_array($value) && !count($value) && isset($headers[$key])) {
                unset($headers[$key]);
            }
        }

        return (object)array_merge($headers, $imap_headers);
    }

    // https://github.com/freescout-help-desk/freescout/issues/4158
    public static function sanitizeHeaderValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $i => $v) {
                if (is_object($v)
                    && isset($v->mailbox)
                    && ($v->mailbox == '>' || $v->mailbox == 'INVALID_ADDRESS') 
                    && ((isset($v->host) && ($v->host == '.SYNTAX-ERROR.' || $v->host === null)) || !isset($v->host))
                ) {
                    echo 'unset: '.$v->mailbox;

                    unset($value[$i]);
                }
            }
        }

        return $value;
    }

    /**
     * Decode MIME header elements
     * @link https://php.net/manual/en/function.imap-mime-header-decode.php
     * @param string $text The MIME text
     *
     * @return array The decoded elements are returned in an array of objects, where each
     * object has two properties, charset and text.
     */
    public function mime_header_decode(string $text): array {

        // imap_mime_header_decode() can't decode some headers: =?iso-2022-jp?B?...?=
        if (\Helper::startsiWith($text, '=?iso-2022-jp?')) {
            return [(object)[
                "charset" => 'iso-2022-jp',
                "text"    => \MailHelper::decodeSubject($text)
            ]];
        }

        if (extension_loaded('imap')) {
            $result = \imap_mime_header_decode($text);
            return is_array($result) ? $result : [];
        }

        $charset = $this->getEncoding($text);
        return [(object)[
            "charset" => $charset,
            "text"    => $this->convertEncoding($text, $charset)
        ]];
    }

    /**
     * Check if a given pair of strings has been decoded
     * @param $encoded
     * @param $decoded
     *
     * @return bool
     */
    private function notDecoded($encoded, $decoded): bool {
        return 0 === strpos($decoded, '=?')
            && strlen($decoded) - 2 === strpos($decoded, '?=')
            && false !== strpos($encoded, $decoded);
    }

    /**
     * Convert the encoding
     * @param $str
     * @param string $from
     * @param string $to
     *
     * @return mixed|string
     */
    public function convertEncoding($str, $from = "ISO-8859-2", $to = "UTF-8") {

        $from = EncodingAliases::get($from, $this->fallback_encoding);
        $to = EncodingAliases::get($to, $this->fallback_encoding);

        if ($from === $to) {
            return $str;
        }

        // We don't need to do convertEncoding() if charset is ASCII (us-ascii):
        //     ASCII is a subset of UTF-8, so all ASCII files are already UTF-8 encoded
        //     https://stackoverflow.com/a/11303410
        //
        // us-ascii is the same as ASCII:
        //     ASCII is the traditional name for the encoding system; the Internet Assigned Numbers Authority (IANA)
        //     prefers the updated name US-ASCII, which clarifies that this system was developed in the US and
        //     based on the typographical symbols predominantly in use there.
        //     https://en.wikipedia.org/wiki/ASCII
        //
        // convertEncoding() function basically means convertToUtf8(), so when we convert ASCII string into UTF-8 it gets broken.
        if (strtolower($from) == 'us-ascii' && $to == 'UTF-8') {
            return $str;
        }

        $from = \MailHelper::substituteEncoding($from);

        try {
            if (function_exists('iconv') && $from != 'UTF-7' && $to != 'UTF-7') {
                return iconv($from, $to, $str);
            } else {
                if (!$from) {
                    return mb_convert_encoding($str, $to);
                }
                return mb_convert_encoding($str, $to, $from);
            }
        } catch (\Exception $e) {
            if (strstr($from, '-')) {
                $from = str_replace('-', '', $from);
                return $this->convertEncoding($str, $from, $to);
            } else {
                return $str;
            }
        }
    }

    /**
     * Get the encoding of a given abject
     * @param object|string $structure
     *
     * @return string
     */
    public function getEncoding($structure): string {
        if (property_exists($structure, 'parameters')) {
            foreach ($structure->parameters as $parameter) {
                if (strtolower($parameter->attribute) == "charset") {
                    return EncodingAliases::get($parameter->value, $this->fallback_encoding);
                }
            }
        } elseif (property_exists($structure, 'charset')) {
            return EncodingAliases::get($structure->charset, $this->fallback_encoding);
        } elseif (is_string($structure) === true) {
            $result = mb_detect_encoding($structure);
            return $result === false ? $this->fallback_encoding : $result;
        }

        return $this->fallback_encoding;
    }

    /**
     * Test if a given value is utf-8 encoded
     * @param $value
     *
     * @return bool
     */
    private function is_uft8($value): bool {
        return strpos(strtolower($value), '=?utf-8?') === 0;
    }

    /**
     * Try to decode a specific header
     * @param mixed $value
     *
     * @return mixed
     */
    private function decode($value) {
        if (is_array($value)) {
            return $this->decodeArray($value);
        }
        $original_value = $value;
        $decoder = $this->config['decoder']['message'];

        if ($value !== null) {
            $is_utf8_base = $this->is_uft8($value);

            if ($decoder === 'utf-8' && extension_loaded('imap')) {
                $value = \imap_utf8($value);
                $is_utf8_base = $this->is_uft8($value);
                if ($is_utf8_base) {
                    $value = mb_decode_mimeheader($value);
                }
                if ($this->notDecoded($original_value, $value)) {
                    $decoded_value = $this->mime_header_decode($value);
                    if (count($decoded_value) > 0) {
                        if (property_exists($decoded_value[0], "text")) {
                            $value = $decoded_value[0]->text;
                        }
                    }
                }
            } elseif ($decoder === 'iconv') {
                $value = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8");
            } elseif ($is_utf8_base) {
                $value = mb_decode_mimeheader($value);
            }

            if ($this->is_uft8($value)) {
                $value = mb_decode_mimeheader($value);
            }

            if ($this->notDecoded($original_value, $value)) {
                $value = $this->convertEncoding($original_value, $this->getEncoding($original_value));
            }
        }

        return $value;
    }

    /**
     * Decode a given array
     * @param array $values
     *
     * @return array
     */
    private function decodeArray(array $values): array {
        foreach ($values as $key => $value) {
            $values[$key] = $this->decode($value);
        }
        return $values;
    }

    /**
     * Try to extract the priority from a given raw header string
     */
    private function findPriority() {
        if (($priority = $this->get("x_priority")) === null) return;
        switch ((int)"$priority") {
            case IMAP::MESSAGE_PRIORITY_HIGHEST;
                $priority = IMAP::MESSAGE_PRIORITY_HIGHEST;
                break;
            case IMAP::MESSAGE_PRIORITY_HIGH;
                $priority = IMAP::MESSAGE_PRIORITY_HIGH;
                break;
            case IMAP::MESSAGE_PRIORITY_NORMAL;
                $priority = IMAP::MESSAGE_PRIORITY_NORMAL;
                break;
            case IMAP::MESSAGE_PRIORITY_LOW;
                $priority = IMAP::MESSAGE_PRIORITY_LOW;
                break;
            case IMAP::MESSAGE_PRIORITY_LOWEST;
                $priority = IMAP::MESSAGE_PRIORITY_LOWEST;
                break;
            default:
                $priority = IMAP::MESSAGE_PRIORITY_UNKNOWN;
                break;
        }

        $this->set("priority", $priority);
    }

    /**
     * Extract a given part as address array from a given header
     * @param $values
     *
     * @return array
     */
    private static function decodeAddresses($values): array {
        $addresses = [];

        // Consider rfc822 option to be always 'true'.
        if (extension_loaded('mailparse') /*&& $this->config["rfc822"]*/) {
            foreach ($values as $address) {
                foreach (\mailparse_rfc822_parse_addresses($address) as $parsed_address) {
                    if (isset($parsed_address['address'])) {
                        $mail_address = explode('@', $parsed_address['address']);
                        if (count($mail_address) == 2) {
                            $addresses[] = (object)[
                                "personal" => $parsed_address['display'] ?? '',
                                "mailbox"  => $mail_address[0],
                                "host"     => $mail_address[1],
                            ];
                        }
                    }
                }
            }

            return $addresses;
        }

        foreach ($values as $address) {
            foreach (preg_split('/, ?(?=(?:[^"]*"[^"]*")*[^"]*$)/', $address) as $split_address) {
                $split_address = trim(rtrim($split_address));

                if (strpos($split_address, ",") == strlen($split_address) - 1) {
                    $split_address = substr($split_address, 0, -1);
                }
                if (preg_match(
                    '/^(?:(?P<name>.+)\s)?(?(name)<|<?)(?P<email>[^\s]+?)(?(name)>|>?)$/',
                    $split_address,
                    $matches
                )) {
                    $name = trim(rtrim($matches["name"]));
                    $email = trim(rtrim($matches["email"]));
                    list($mailbox, $host) = array_pad(explode("@", $email), 2, null);
                    $addresses[] = (object)[
                        "personal" => $name,
                        "mailbox"  => $mailbox,
                        "host"     => $host,
                    ];
                }
            }
        }

        return $addresses;
    }

    /**
     * Extract a given part as address array from a given header
     * @param object $header
     */
    private function extractAddresses($header) {
        foreach (['from', 'to', 'cc', 'bcc', 'reply_to', 'sender'] as $key) {
            if (property_exists($header, $key)) {
                $this->set($key, $this->parseAddresses($header->$key));
            }
        }
    }

    /**
     * Parse Addresses
     * @param $list
     *
     * @return array
     */
    private function parseAddresses($list): array {
        $addresses = [];

        if (is_array($list) === false) {
            // https://github.com/Webklex/php-imap/commit/916e273d102c6e4b8f10363a500d8caa6ab94111
            if (is_string($list)) {
                // $list = "<noreply@github.com>"
                if (preg_match(
                    '/^(?:(?P<name>.+)\s)?(?(name)<|<?)(?P<email>[^\s]+?)(?(name)>|>?)$/',
                    $list,
                    $matches
                )) {
                    $name = trim(rtrim($matches["name"]));
                    $email = trim(rtrim($matches["email"]));
                    list($mailbox, $host) = array_pad(explode("@", $email), 2, null);
                    if ($mailbox === ">") { // Fix trailing ">" in malformed mailboxes
                        $mailbox = "";
                    }
                    if ($name === "" && $mailbox === "" && $host === "") {
                        return $addresses;
                    }
                    $list = [
                        (object)[
                            "personal" => $name,
                            "mailbox"  => $mailbox,
                            "host"     => $host,
                        ]
                    ];
                } else {
                    return $addresses;
                }
            } else {
                return $addresses;
            }
        }

        foreach ($list as $item) {
            $address = (object)$item;

            if (!property_exists($address, 'mailbox')) {
                $address->mailbox = false;
            }
            if (!property_exists($address, 'host')) {
                $address->host = false;
            }
            if (!property_exists($address, 'personal')) {
                $address->personal = false;
            } else {
                // $personalParts = $this->mime_header_decode($address->personal);

                // if (is_array($personalParts)) {
                //     $address->personal = '';
                //     foreach ($personalParts as $p) {
                //         $address->personal .= $this->convertEncoding($p->text, $this->getEncoding($p));
                //     }
                // }

                // if (strpos($address->personal, "'") === 0) {
                //     $address->personal = str_replace("'", "", $address->personal);
                // }

                $personal_slices = explode(" ", $address->personal);
                $address->personal = "";
                foreach ($personal_slices as $slice) {
                    $personalParts = $this->mime_header_decode($slice);

                    if (is_array($personalParts)) {
                        $personal = '';
                        foreach ($personalParts as $p) {
                            $personal .= $this->convertEncoding($p->text, $this->getEncoding($p));
                        }
                    }

                    if (\Str::startsWith($personal, "'")) {
                        $personal = str_replace("'", "", $personal);
                    }
                    $personal = \MailHelper::decodeSubject($personal);
                    $address->personal .= $personal . " ";
                }
                $address->personal = trim(rtrim($address->personal));
            }

            $address->mail = ($address->mailbox && $address->host) ? $address->mailbox . '@' . $address->host : false;
            $address->full = ($address->personal) ? $address->personal . ' <' . $address->mail . '>' : $address->mail;

            $addresses[] = new Address($address);
        }

        return $addresses;
    }

    /**
     * Search and extract potential header extensions
     */
    private function extractHeaderExtensions() {
        foreach ($this->attributes as $key => $value) {
            if (is_array($value)) {
                $value = implode(", ", $value);
            } else {
                $value = (string)$value;
            }
            // Only parse strings and don't parse any attributes like the user-agent
            // https://github.com/Webklex/php-imap/issues/401
            // https://github.com/Webklex/php-imap/commit/e5ad66267382f319f385131cefe5336692a54486
           if (!in_array($key, ["user-agent", "subject", "received"])) {
                if (str_contains($value, ";") && str_contains($value, "=")) {
                    $_attributes = $this->read_attribute($value);
                    foreach($_attributes as $_key => $_value) {
                        if ($_value === "") {
                            // Remove existing value.
                            if (isset($this->attributes[$key])) {
                                unset($this->attributes[$key]);
                            }
                            // Set value.
                            $this->set($key, $_key);
                        }
                        if (!isset($this->attributes[$_key])) {
                            $this->set($_key, $_value);
                        }
                    }
                }
            }
        }
    }

    /**
     * Read a given attribute string
     * - this isn't pretty, but it works - feel free to improve :)
     * @param string $raw_attribute
     * @return array
     */
    private function read_attribute(string $raw_attribute): array {
        $attributes = [];
        $key = '';
        $value = '';
        $inside_word = false;
        $inside_key = true;
        $escaped = false;
        foreach (str_split($raw_attribute) as $char) {
            if($escaped) {
                $escaped = false;
                continue;
            }
            if($inside_word) {
                if($char === '\\') {
                    $escaped = true;
                }elseif($char === "\"" && $value !== "") {
                    $inside_word = false;
                }else{
                    $value .= $char;
                }
            }else{
                if($inside_key) {
                    if($char === '"') {
                        $inside_word = true;
                    }elseif($char === ';'){
                        $attributes[$key] = $value;
                        $key = '';
                        $value = '';
                        $inside_key = true;
                    }elseif($char === '=') {
                        $inside_key = false;
                    }else{
                        $key .= $char;
                    }
                }else{
                    if($char === '"' && $value === "") {
                        $inside_word = true;
                    }elseif($char === ';'){
                        $attributes[$key] = $value;
                        $key = '';
                        $value = '';
                        $inside_key = true;
                    }else{
                        $value .= $char;
                    }
                }
            }
        }
        $attributes[$key] = $value;
        $result = [];

        foreach($attributes as $key => $value) {
            if (($pos = strpos($key, "*")) !== false) {
                $key = substr($key, 0, $pos);
            }
            $key = trim(rtrim(strtolower($key)));

            if(!isset($result[$key])) {
                $result[$key] = "";
            }
            $value = trim(rtrim(str_replace(["\r", "\n"], "", $value)));
            if (\Str::startsWith($value, "\"") && \Str::endsWith($value, "\"")) {
                $value = substr($value, 1, -1);
            }
            $result[$key] .= $value;
        }
        return $result;
    }

    /**
     * Exception handling for invalid dates
     *
     * Currently known invalid formats:
     * ^ Datetime                                   ^ Problem                           ^ Cause
     * | Mon, 20 Nov 2017 20:31:31 +0800 (GMT+8:00) | Double timezone specification     | A Windows feature
     * | Thu, 8 Nov 2018 08:54:58 -0200 (-02)       |
     * |                                            | and invalid timezone (max 6 char) |
     * | 04 Jan 2018 10:12:47 UT                    | Missing letter "C"                | Unknown
     * | Thu, 31 May 2018 18:15:00 +0800 (added by) | Non-standard details added by the | Unknown
     * |                                            | mail server                       |
     * | Sat, 31 Aug 2013 20:08:23 +0580            | Invalid timezone                  | PHPMailer bug https://sourceforge.net/p/phpmailer/mailman/message/6132703/
     *
     * Please report any new invalid timestamps to [#45](https://github.com/Webklex/php-imap/issues)
     *
     * @param object $header
     *
     * @throws InvalidMessageDateException
     */
    private function parseDate($header) {

        if (property_exists($header, 'date')) {
            $date = $header->date;

            try {
                $parsed_date = self::doParseDate($date);
            } catch (\Exception $e) {
                if (!isset($this->config["fallback_date"])) {
                    // Simply use current date.
                    // https://github.com/freescout-help-desk/freescout/issues/4159
                    $parsed_date = Carbon::now();
                    \Helper::logException(new InvalidMessageDateException("Invalid message date. ID:" . $this->get("message_id") . " Date:" . $header->date . "/" . $date, 1100, $e));

                    //throw new InvalidMessageDateException("Invalid message date. ID:" . $this->get("message_id") . " Date:" . $header->date . "/" . $date, 1100, $e);
                } else {
                    $parsed_date = Carbon::parse($this->config["fallback_date"]);
                }
            }

            $this->set("date", $parsed_date);
        }
    }

    public static function doParseDate($date)
    {
        if (preg_match('/\+0580/', $date)) {
            $date = str_replace('+0580', '+0530', $date);
        }

        $date = trim(rtrim($date));
        try {
            if(strpos($date, '&nbsp;') !== false){
                $date = str_replace('&nbsp;', ' ', $date);
            }
            if (str_contains($date, ' UT ')) {
                $date = str_replace(' UT ', ' UTC ', $date);
            }
            $parsed_date = Carbon::parse($date);
        } catch (\Exception $e) {
            switch (true) {
                case preg_match('/([0-9]{4}\.[0-9]{1,2}\.[0-9]{1,2}\-[0-9]{1,2}\.[0-9]{1,2}.[0-9]{1,2})+$/i', $date) > 0:
                    $date = Carbon::createFromFormat("Y.m.d-H.i.s", $date);
                    break;
                case preg_match('/([0-9]{2} [A-Z]{3} [0-9]{4} [0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2} [+-][0-9]{1,4} [0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2} [+-][0-9]{1,4})+$/i', $date) > 0:
                    $parts = explode(' ', $date);
                    array_splice($parts, -2);
                    $date = implode(' ', $parts);
                    break;
                case preg_match('/([A-Z]{2,4}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})+$/i', $date) > 0:
                    $array = explode(',', $date);
                    array_shift($array);
                    $date = Carbon::createFromFormat("d M Y H:i:s O", trim(implode(',', $array)));
                    break;
                case preg_match('/([0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ UT)+$/i', $date) > 0:
                case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ ([0-9]{2}|[0-9]{4})\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ UT)+$/i', $date) > 0:
                    $date .= 'C';
                    break;
                case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}[\,]\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})+$/i', $date) > 0:
                    $date = str_replace(',', '', $date);
                    break;
                // match case for: Di., 15 Feb. 2022 06:52:44 +0100 (MEZ)/Di., 15 Feb. 2022 06:52:44 +0100 (MEZ) and Mi., 23 Apr. 2025 09:48:37 +0200 (MESZ)
                case preg_match('/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \([A-Z]{3,4}\))(\/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \([A-Z]{3,4}\))+)?$/i', $date) > 0:
                    $dates = explode('/', $date);
                    $date = array_shift($dates);
                    $array = explode(',', $date);
                    array_shift($array);
                    $date = trim(implode(',', $array));
                    $array = explode(' ', $date);
                    array_pop($array);
                    $date = trim(implode(' ', $array));
                    $date = Carbon::createFromFormat("d M. Y H:i:s O", $date);
                    break;
                // match case for: fr., 25 nov. 2022 06:27:14 +0100/fr., 25 nov. 2022 06:27:14 +0100
                case preg_match('/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})\/([A-Z]{2,3}\.\,\ [0-9]{1,2}\ [A-Z]{2,3}\.\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4})+$/i', $date) > 0:
                    $dates = explode('/', $date);
                    $date = array_shift($dates);
                    $array = explode(',', $date);
                    array_shift($array);
                    $date = trim(implode(',', $array));
                    $date = Carbon::createFromFormat("d M. Y H:i:s O", $date);
                    break;
                case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ \+[0-9]{2,4}\ \(\+[0-9]{1,2}\))+$/i', $date) > 0:
                case preg_match('/([A-Z]{2,3}[\,|\ \,]\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}.*)+$/i', $date) > 0:
                case preg_match('/([A-Z]{2,3}\,\ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \(.*)\)+$/i', $date) > 0:
                case preg_match('/([A-Z]{2,3}\, \ [0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{4}\ [0-9]{1,2}\:[0-9]{1,2}\:[0-9]{1,2}\ [\-|\+][0-9]{4}\ \(.*)\)+$/i', $date) > 0:
                case preg_match('/([0-9]{1,2}\ [A-Z]{2,3}\ [0-9]{2,4}\ [0-9]{2}\:[0-9]{2}\:[0-9]{2}\ [A-Z]{2}\ \-[0-9]{2}\:[0-9]{2}\ \([A-Z]{2,3}\ \-[0-9]{2}:[0-9]{2}\))+$/i', $date) > 0:
                    $array = explode('(', $date);
                    $array = array_reverse($array);
                    $date = trim(array_pop($array));
                    break;
            }
            try {
                $parsed_date = Carbon::parse($date);
            } catch (\Exception $_e) {
                throw $_e;
            }
        }

        return $parsed_date;
    }

    /**
     * Get all available attributes
     *
     * @return array
     */
    public function getAttributes(): array {
        return $this->attributes;
    }

}
