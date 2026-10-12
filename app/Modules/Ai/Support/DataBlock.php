<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/**
 * Prompt-injection hygiene: record content reaches the model only as an encoded data block.
 * JSON encoding (with tag characters hex-escaped) means content can neither close the block nor
 * pose as a message boundary; the system prompt states that block content is never an instruction.
 */
final class DataBlock
{
    /**
     * A tool result: `{"untrusted_data": ...}`. The argument is the size-limited JSON of the tool output.
     */
    public static function toolResult(string $limitedJson): string
    {
        return '{"untrusted_data":'.$limitedJson.'}';
    }

    /**
     * A record handed to a drafting call.
     *
     * @param  array<string, mixed>  $data
     */
    public static function record(string $source, string $id, array $data): string
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $source = (string) preg_replace('/[^a-z_]/', '', $source);
        $id = (string) preg_replace('/[^0-9A-Za-z]/', '', $id);

        return '<record_data source="'.$source.'" id="'.$id.'">'.$json.'</record_data>';
    }

    /**
     * A record whose JSON was already produced (and size-limited) by a tool run.
     */
    public static function wrapJson(string $source, string $id, string $json): string
    {
        $source = (string) preg_replace('/[^a-z_]/', '', $source);
        $id = (string) preg_replace('/[^0-9A-Za-z]/', '', $id);

        return '<record_data source="'.$source.'" id="'.$id.'">'.$json.'</record_data>';
    }

    /**
     * Free text supplied by a person for rewriting: also data, never instructions.
     */
    public static function text(string $text): string
    {
        $json = (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return '<text_data>'.$json.'</text_data>';
    }
}
