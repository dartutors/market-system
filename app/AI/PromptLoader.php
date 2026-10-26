<?php
declare(strict_types=1);
namespace App\AI;

use RuntimeException;

/**
 * Loads a prompt template from resources/prompts/{name}.md and substitutes
 * {{var}} placeholders. File format:
 *
 *   <!-- version: 1.0 -->
 *   ## SYSTEM
 *   ...system prompt text...
 *   ## USER
 *   ...user prompt text with {{placeholders}}...
 */
final class PromptLoader
{
    /**
     * @return array{system:string, user:string}
     */
    public static function load(string $name, array $vars = []): array
    {
        $path = base_path('resources/prompts/' . $name . '.md');
        if (!is_file($path)) {
            throw new RuntimeException("Prompt template not found: {$name}.md");
        }

        $raw = (string) file_get_contents($path);
        $raw = (string) preg_replace('/^<!--.*?-->\s*/s', '', $raw, 1);

        $parts = preg_split('/^##\s*(SYSTEM|USER)\s*$/mi', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);

        $sections = ['system' => '', 'user' => ''];
        for ($i = 1, $count = \count($parts); $i < $count; $i += 2) {
            $marker  = strtolower($parts[$i]);
            $content = $parts[$i + 1] ?? '';
            if (isset($sections[$marker])) {
                $sections[$marker] = trim($content);
            }
        }

        return [
            'system' => self::substitute($sections['system'], $vars),
            'user'   => self::substitute($sections['user'], $vars),
        ];
    }

    private static function substitute(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }
}
