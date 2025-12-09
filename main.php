<?php

class EDIFACTBaseError extends Exception
{
    protected $code;
    protected $details;

    public function __construct($message, $code = "", $details = [])
    {
        $this->code = $code;
        $this->details = $details;
        parent::__construct($code . ": " . $message);
    }

    public function getDetails()
    {
        return $this->details;
    }

    public function getErrorCode()
    {
        return $this->code;
    }
}

class EDIFACTValidationError extends EDIFACTBaseError
{
    public function __construct($message, $code = "VALID_001", $details = [])
    {
        parent::__construct($message, $code, $details);
    }
}

class EDIFACTGenerationError extends EDIFACTBaseError
{
    public function __construct($message, $code = "GEN_001", $details = [])
    {
        parent::__construct($message, $code, $details);
    }
}

class EDIFACTConfig
{
    public $SUPPORTED_CHARSETS = ["UNOA", "UNOB", "UNOC"];
    public $SUPPORTED_CURRENCIES = ["EUR", "USD", "GBP", "JPY", "CAD"];
    public $SUPPORTED_DATE_FORMATS = ["102", "203", "101"];
    public $MAX_PARTY_ID_LENGTH = 35;
    public $MAX_NAME_LENGTH = 70;
    public $MAX_ITEM_ID_LENGTH = 35;
    public $MAX_TEXT_LENGTH = 350;
    public $SEGMENT_TERMINATOR = "'";
    public $DATA_ELEMENT_SEPARATOR = "+";
    public $COMPONENT_SEPARATOR = ":";
    public $MAX_SEGMENT_LENGTH = 2000;
    public $DEFAULT_PRECISION = 2;

    public function __construct($kwargs = [])
    {
        foreach ($kwargs as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

class EDIFACTValidator
{
    private static $DATE_FORMATS = [
        "102" => "Ymd",
        "203" => "YmdHi",
        "101" => "ymd"
    ];

    public static function validateSchema($data)
    {
        $requiredFields = ["invoice_number", "invoice_date", "currency", "parties", "items"];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new EDIFACTValidationError(
                    "Missing required field: $field",
                    "SCHEMA_001",
                    ["missing_field" => $field]
                );
            }
        }

        self::_validateFieldLength("invoice_number", (string)($data["invoice_number"] ?? ""), 35);

        if (isset($data["currency"])) {
            if (strlen((string)$data["currency"]) > 3) {
                throw new EDIFACTValidationError(
                    "Currency code must be 3 characters",
                    "SCHEMA_002",
                    ["currency" => $data["currency"]]
                );
            }
        }

        if (!is_array($data["parties"] ?? null) || !isset($data["parties"]["buyer"]) || !isset($data["parties"]["seller"])) {
            throw new EDIFACTValidationError(
                "Both buyer and seller parties are required",
                "SCHEMA_003"
            );
        }

        foreach (["buyer", "seller"] as $party) {
            if (!is_array($data["parties"][$party] ?? null)) {
                throw new EDIFACTValidationError(
                    "$party must be an object",
                    "SCHEMA_004",
                    ["party" => $party]
                );
            }

            if (!isset($data["parties"][$party]["id"])) {
                throw new EDIFACTValidationError(
                    "$party ID is required",
                    "SCHEMA_005",
                    ["party" => $party]
                );
            }

            $config = new EDIFACTConfig();
            self::_validateFieldLength("id", (string)($data["parties"][$party]["id"] ?? ""), $config->MAX_PARTY_ID_LENGTH);

            if (isset($data["parties"][$party]["name"])) {
                self::_validateFieldLength("name", (string)$data["parties"][$party]["name"], $config->MAX_NAME_LENGTH);
            }
        }

        if (!is_array($data["items"] ?? null) || count($data["items"] ?? []) < 1) {
            throw new EDIFACTValidationError(
                "At least one item is required",
                "SCHEMA_006",
                ["items_count" => count($data["items"] ?? [])]
            );
        }

        foreach ($data["items"] as $idx => $item) {
            if (!is_array($item)) {
                throw new EDIFACTValidationError(
                    "Item $idx must be an object",
                    "SCHEMA_007",
                    ["item_index" => $idx]
                );
            }

            if (!isset($item["id"]) || !isset($item["quantity"]) || !isset($item["price"])) {
                throw new EDIFACTValidationError(
                    "Item $idx must contain id, quantity, and price",
                    "SCHEMA_008",
                    ["item_index" => $idx]
                );
            }

            $config = new EDIFACTConfig();
            self::_validateFieldLength("id", (string)($item["id"] ?? ""), $config->MAX_ITEM_ID_LENGTH);
        }

        if (isset($data["notes"])) {
            $config = new EDIFACTConfig();
            self::_validateFieldLength("notes", (string)$data["notes"], $config->MAX_TEXT_LENGTH);
        }
    }

    private static function _validateFieldLength($fieldName, $value, $maxLength)
    {
        if (strlen($value) > $maxLength) {
            throw new EDIFACTValidationError(
                "Field '$fieldName' exceeds maximum length of $maxLength",
                "SCHEMA_009",
                ["field" => $fieldName, "value" => substr($value, 0, 50), "length" => strlen($value)]
            );
        }
    }

    public static function validateFields($data, $config)
    {
        if (isset($data["charset"]) && !in_array($data["charset"], $config->SUPPORTED_CHARSETS)) {
            throw new EDIFACTValidationError("Unsupported charset: " . $data["charset"], "VALID_002");
        }

        if (!in_array($data["currency"], $config->SUPPORTED_CURRENCIES)) {
            throw new EDIFACTValidationError("Unsupported currency: " . $data["currency"], "VALID_003");
        }

        self::_validateDate($data["invoice_date"], "invoice_date", "102");
        if (isset($data["due_date"])) {
            self::_validateDate($data["due_date"], "due_date", "102");
        }

        foreach (["buyer", "seller"] as $party) {
            self::_validateParty($data["parties"][$party], $party, $config);
        }

        foreach ($data["items"] as $idx => $item) {
            self::_validateItem($item, $idx);
        }

        self::_validateInterdependencies($data);
    }

    private static function _validateDate($dateStr, $fieldName, $dateFormat)
    {
        $fmt = self::$DATE_FORMATS[$dateFormat] ?? null;
        if (!$fmt) {
            throw new EDIFACTValidationError("Unsupported date format: $dateFormat", "VALID_004");
        }

        $date = DateTime::createFromFormat($fmt, $dateStr);
        if (!$date || $date->format($fmt) !== $dateStr) {
            throw new EDIFACTValidationError("Invalid date in $fieldName: $dateStr", "VALID_005");
        }
    }

    private static function _validateParty($party, $role, $config)
    {
        if (empty($party["id"])) {
            throw new EDIFACTValidationError("$role ID is required", "VALID_006");
        }

        if (strlen($party["id"]) > $config->MAX_PARTY_ID_LENGTH) {
            throw new EDIFACTValidationError(
                "$role ID too long: " . strlen($party["id"]) . " > " . $config->MAX_PARTY_ID_LENGTH,
                "VALID_007",
                ["role" => $role, "length" => strlen($party["id"])]
            );
        }

        if (isset($party["name"]) && strlen($party["name"]) > $config->MAX_NAME_LENGTH) {
            throw new EDIFACTValidationError(
                "$role name too long: " . strlen($party["name"]) . " > " . $config->MAX_NAME_LENGTH,
                "VALID_008",
                ["role" => $role, "length" => strlen($party["name"])]
            );
        }
    }

    private static function _validateItem($item, $index)
    {
        $config = new EDIFACTConfig();
        if (strlen($item["id"]) > $config->MAX_ITEM_ID_LENGTH) {
            throw new EDIFACTValidationError(
                "Item $index ID too long: " . strlen($item["id"]) . " > " . $config->MAX_ITEM_ID_LENGTH,
                "VALID_009",
                ["item_index" => $index, "length" => strlen($item["id"])]
            );
        }

        $quantity = floatval($item["quantity"]);
        if ($quantity <= 0) {
            throw new EDIFACTValidationError(
                "Item $index quantity must be positive",
                "VALID_010",
                ["item_index" => $index, "quantity" => $item["quantity"]]
            );
        }

        $price = floatval($item["price"]);
        if ($price < 0) {
            throw new EDIFACTValidationError(
                "Item $index price must be non-negative",
                "VALID_011",
                ["item_index" => $index, "price" => $item["price"]]
            );
        }
    }

    private static function _validateInterdependencies($data)
    {
        if (isset($data["due_date"])) {
            $invoiceDate = DateTime::createFromFormat("Ymd", $data["invoice_date"]);
            $dueDate = DateTime::createFromFormat("Ymd", $data["due_date"]);
            if ($dueDate <= $invoiceDate) {
                throw new EDIFACTValidationError("Due date must be after invoice date", "VALID_012");
            }
        }

        $itemIds = array_map(function ($item) {
            return $item["id"];
        }, $data["items"]);

        if (count($itemIds) !== count(array_unique($itemIds))) {
            throw new EDIFACTValidationError("Item IDs must be unique", "VALID_013");
        }
    }
}

class EDIFACTGenerator
{
    private $data;
    private $config;
    private $lineEnding;
    private $messageRef;
    private $segments = [];

    private const ESCAPE_CHARS = ["'", "+", ":", "*"];

    public function __construct($data, $config = null, $lineEnding = "\n")
    {
        $this->data = $this->_sanitizeInput($data);
        $this->config = $config ?? new EDIFACTConfig();
        $this->lineEnding = $lineEnding;
        $this->messageRef = $data["message_ref"] ?? substr(uniqid('', true), 0, 14);
    }

    private function _sanitizeInput($data)
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->_sanitizeInput($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function _formatDecimal($value)
    {
        try {
            $value = floatval($value);
            $precision = $this->config->DEFAULT_PRECISION;
            
            $rounded = round($value, $precision);
            
            if (abs($value - $rounded) > pow(10, -$precision - 1)) {
                throw new EDIFACTGenerationError(
                    "Decimal value $value exceeds configured precision",
                    "GEN_002",
                    ["value" => $value, "precision" => $precision]
                );
            }
            
            $formatted = number_format($rounded, $precision, '.', '');
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
            
            return $formatted;
        } catch (Exception $e) {
            throw new EDIFACTGenerationError("Invalid numeric value: $value", "GEN_003", ["error" => $e->getMessage()]);
        }
    }

    private function _escapeSegmentValue($value)
    {
        if ($value === null) {
            return "";
        }

        $result = "";
        $chars = str_split((string)$value);
        foreach ($chars as $char) {
            if (preg_match('/[\x00-\x1F\x7F]/', $char)) {
                continue;
            } elseif ($char === '?') {
                $result .= '??';
            } elseif (in_array($char, self::ESCAPE_CHARS)) {
                $result .= '?' . $char;
            } else {
                $result .= $char;
            }
        }
        return $result;
    }

    private function _validateSegmentLength($segment)
    {
        if (strlen($segment) > $this->config->MAX_SEGMENT_LENGTH) {
            throw new EDIFACTGenerationError(
                "Segment too long: " . strlen($segment) . " > " . $this->config->MAX_SEGMENT_LENGTH,
                "GEN_004",
                ["segment" => substr($segment, 0, 100), "length" => strlen($segment)]
            );
        }
    }

    private function _buildSegment($tag, $elements)
    {
        if (empty($elements)) {
            $elements = [];
        }

        $escapedElements = array_map([$this, '_escapeSegmentValue'], $elements);
        $segment = $tag . "+" . implode("+", $escapedElements) . "'";

        $this->_validateSegmentLength($segment);
        return $segment;
    }

    private function _addUnaSegment()
    {
        $charset = $this->data["charset"] ?? "UNOC";
        $this->segments[] = "UNA:+.? '";
    }

    private function _addUnbSegment()
    {
        $timestamp = date("ymdHi");
        $senderId = $this->data["sender_id"] ?? "SENDER";
        $receiverId = $this->data["receiver_id"] ?? "RECEIVER";
        $charset = $this->data["charset"] ?? "UNOC";
        $version = "3";
        $this->segments[] = $this->_buildSegment("UNB", ["$charset:$version", $senderId, $receiverId, $timestamp, $this->messageRef]);
    }

    private function _addUnzSegment()
    {
        $this->segments[] = $this->_buildSegment("UNZ", ["1", $this->messageRef]);
    }

    private function _addHeaderSegments()
    {
        $this->segments[] = $this->_buildSegment("UNH", [$this->messageRef, "INVOIC:D:96A:UN"]);
        $this->segments[] = $this->_buildSegment("BGM", ["380", $this->data["invoice_number"], "9"]);
        $this->segments[] = $this->_buildSegment("DTM", ["137", $this->data["invoice_date"], "102"]);

        if (isset($this->data["due_date"])) {
            $this->segments[] = $this->_buildSegment("DTM", ["13", $this->data["due_date"], "102"]);
        }
    }

    private function _addCurrencySegment()
    {
        $this->segments[] = $this->_buildSegment("CUX", ["2", $this->data["currency"], "9"]);
    }

    private function _addPartySegments()
    {
        $roles = ["buyer" => "BY", "seller" => "SE"];
        foreach ($roles as $role => $code) {
            $party = $this->data["parties"][$role];
            $this->segments[] = $this->_buildSegment("NAD", [$code, $party["id"], "", "91", $party["name"] ?? ""]);

            if (isset($party["address"])) {
                $this->segments[] = $this->_buildSegment("LOC", ["11", $party["address"]]);
            }

            if (isset($party["contact"])) {
                $this->segments[] = $this->_buildSegment("COM", [$party["contact"], "TE"]);
            }
        }
    }

    private function _addLineItems()
    {
        $idx = 1;
        foreach ($this->data["items"] as $item) {
            $this->segments[] = $this->_buildSegment("LIN", [(string)$idx, "", $item["id"], "EN"]);

            if (isset($item["description"])) {
                $this->segments[] = $this->_buildSegment("IMD", ["F", "", "", "", $item["description"]]);
            }

            $unit = $item["unit"] ?? "PCE";
            $this->segments[] = $this->_buildSegment("QTY", ["47", $this->_formatDecimal($item["quantity"]), $unit]);
            $this->segments[] = $this->_buildSegment("PRI", ["AAA", $this->_formatDecimal($item["price"]), $unit]);
            $idx++;
        }
    }

    private function _addFtxSegments()
    {
        if (isset($this->data["notes"])) {
            $notes = $this->data["notes"];
            $maxLength = 70;
            $chunks = str_split($notes, $maxLength);
            $i = 1;
            foreach ($chunks as $chunk) {
                $this->segments[] = $this->_buildSegment("FTX", ["AAI", (string)$i, "", "", $chunk]);
                $i++;
            }
        }
    }

    private function _addPaymentInstructions()
    {
        if (isset($this->data["bank_account"])) {
            $bankData = $this->data["bank_account"];
            if (isset($bankData["account"]) && isset($bankData["bank_code"])) {
                $this->segments[] = $this->_buildSegment("FII", ["BE", "", $bankData["account"], "", $bankData["bank_code"]]);
            }
        }
    }

    private function _addSummarySegments()
    {
        $subtotal = 0.0;
        foreach ($this->data["items"] as $item) {
            $quantity = floatval($item["quantity"]);
            $price = floatval($item["price"]);
            $subtotal += $quantity * $price;
        }

        $precision = $this->config->DEFAULT_PRECISION;
        $subtotalQuantized = round($subtotal, $precision);
        
        $this->segments[] = $this->_buildSegment("MOA", ["79", $this->_formatDecimal($subtotalQuantized)]);

        if (isset($this->data["tax_rate"])) {
            $taxRate = floatval($this->data["tax_rate"]);
            $taxAmount = round(($subtotal * $taxRate / 100.0), $precision);
            
            $this->segments[] = $this->_buildSegment("TAX", ["7", "VAT", "", "", "", "", $this->_formatDecimal($taxRate)]);
            $this->segments[] = $this->_buildSegment("MOA", ["124", $this->_formatDecimal($taxAmount)]);
            
            $totalAmount = $subtotalQuantized + $taxAmount;
            $this->segments[] = $this->_buildSegment("MOA", ["86", $this->_formatDecimal($totalAmount)]);
        } else {
            $this->segments[] = $this->_buildSegment("MOA", ["86", $this->_formatDecimal($subtotalQuantized)]);
        }

        if (isset($this->data["payment_terms"])) {
            $this->segments[] = $this->_buildSegment("PAI", [$this->data["payment_terms"], "3"]);
        }
    }

    private function _addUntSegment()
    {
        $unhIndices = [];
        foreach ($this->segments as $i => $segment) {
            if (strpos($segment, "UNH+") === 0) {
                $unhIndices[] = $i;
            }
        }

        if (empty($unhIndices)) {
            throw new EDIFACTGenerationError("UNH segment not found", "GEN_005");
        }

        $unhIndex = $unhIndices[0];
        $segmentCount = count($this->segments) - $unhIndex;
        $this->segments[] = $this->_buildSegment("UNT", [(string)$segmentCount, $this->messageRef]);
    }

    public function generate()
    {
        EDIFACTValidator::validateSchema($this->data);
        EDIFACTValidator::validateFields($this->data, $this->config);

        $this->segments = [];

        $this->_addUnaSegment();
        $this->_addUnbSegment();
        $this->_addHeaderSegments();
        $this->_addCurrencySegment();
        $this->_addPartySegments();
        $this->_addLineItems();
        $this->_addFtxSegments();
        $this->_addPaymentInstructions();
        $this->_addSummarySegments();
        $this->_addUntSegment();
        $this->_addUnzSegment();

        $edifactContent = implode($this->lineEnding, $this->segments);

        if (!$this->validateEdifactSyntax($edifactContent)) {
            throw new EDIFACTGenerationError("Generated EDIFACT content failed syntax validation", "GEN_006");
        }

        return $edifactContent;
    }

    public function validateEdifactSyntax($content)
    {
        $lines = explode($this->lineEnding, $content);
        if (strpos($lines[0], "UNA") !== 0) {
            return false;
        }

        foreach (array_slice($lines, 1) as $line) {
            if (substr($line, -1) !== "'") {
                return false;
            }
        }

        return true;
    }

    private function _validateFilePath($filename)
    {
        if (!$filename) {
            return;
        }

        $safeFilename = basename($filename);
        if ($safeFilename !== $filename) {
            throw new EDIFACTGenerationError("Invalid filename provided", "IO_001");
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['edi', 'edifact'])) {
            error_log("Recommended file extension is .edi or .edifact");
        }
    }

    public function saveToFile($filename = null)
    {
        $message = $this->generate();
        if (!$filename) {
            $filename = "invoice_" . $this->data["invoice_number"] . ".edi";
        }

        $this->_validateFilePath($filename);

        try {
            file_put_contents($filename, $message);
            return $filename;
        } catch (Exception $e) {
            throw new EDIFACTGenerationError("Failed to write file: " . $e->getMessage(), "IO_002");
        }
    }

    public static function fromJsonFile($filepath, $kwargs = [])
    {
        try {
            $data = json_decode(file_get_contents($filepath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON decode error: " . json_last_error_msg());
            }
            return new self($data, $kwargs);
        } catch (Exception $e) {
            throw new EDIFACTGenerationError("Failed to load JSON file: " . $e->getMessage(), "IO_003");
        }
    }

    public function toArray()
    {
        return $this->data;
    }
}

$exampleInvoice = [
    "invoice_number" => "INV12345",
    "invoice_date" => "20250509",
    "due_date" => "20250609",
    "currency" => "EUR",
    "tax_rate" => 21.0,
    "payment_terms" => "NET30",
    "sender_id" => "COMPANY_A",
    "receiver_id" => "COMPANY_B",
    "notes" => "Thank you for your business. Please note that payments should be made within 30 days.",
    "bank_account" => [
        "account" => "NL91ABNA0417164300",
        "bank_code" => "ABNANL2A"
    ],
    "parties" => [
        "buyer" => [
            "id" => "BUYER123",
            "name" => "Buyer Corporation",
            "address" => "123 Main St",
            "contact" => "buyer@example.com"
        ],
        "seller" => [
            "id" => "SELLER456",
            "name" => "Seller Ltd",
            "address" => "456 Oak Ave",
            "contact" => "sales@seller.com"
        ],
    ],
    "items" => [
        [
            "id" => "ITEM001",
            "description" => "Premium Widget",
            "quantity" => 10,
            "price" => 25.50,
            "unit" => "PCE"
        ],
        [
            "id" => "ITEM002",
            "description" => "Standard Widget",
            "quantity" => 5,
            "price" => 15.75,
            "unit" => "PCE"
        ],
    ],
];

try {
    $config = new EDIFACTConfig(["DEFAULT_PRECISION" => 2]);
    $generator = new EDIFACTGenerator($exampleInvoice, $config, "\r\n");
    $filepath = $generator->saveToFile();
    echo "EDIFACT file generated: $filepath\n";

    echo "\nGenerated EDIFACT content:\n";
    echo file_get_contents($filepath);

} catch (EDIFACTBaseError $e) {
    error_log("EDIFACT generation failed: " . $e->getMessage());
    if (method_exists($e, 'getDetails') && $e->getDetails()) {
        error_log("Error details: " . print_r($e->getDetails(), true));
    }
}

?>
