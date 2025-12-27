<?php

namespace App\Core\Actions;

use \Closure;
use \ReflectionFunction;
use \ReflectionException;

/**
 * A library for securely serializing and executing PHP Closures.
 */
class ActionsClosure
{
    /**
     * The underlying native closure.
     */
    protected ?Closure $closure;

    protected array $args = [];

    /**
     * @param Closure $closure
     * @param array $args
     */
    public function __construct(Closure $closure, array $args = [])
    {
        $this->args = $args;
        $this->closure = $closure;
    }

    /**
     * Execute the closure directly.
     */
    public function __invoke(...$runtimeArgs)
    {
        $args = !empty($this->args) ? $this->args : $runtimeArgs;

        return call_user_func_array($this->closure, $args);
    }

    /**
     * Get the raw closure
     */
    public function getClosure(): Closure
    {
        return $this->closure;
    }

    /**
     * Get the stored arguments
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * Serialize with HMAC signature
     *
     * @return array The data to be serialized.
     */
    public function __serialize(): array
    {
        try {
            $reflection = new ReflectionFunction($this->closure);
        } catch (ReflectionException $e) {
            throw new ActionsException('Closure reflection failed: ' . $e->getMessage());
        }

        $code = $this->extractCode($reflection);

        // Wrap Context AND Arguments (recursively handles nested closures in args)
        $context = $this->wrapClosures($reflection->getStaticVariables());
        $args = $this->wrapClosures($this->args);

        // Force Static Scope
        // We purposefully ignore $this binding to save massive amounts of space.
        // If your closure needs $this, you must pass specific properties via use().
        $binding = null;

        $payloadData = [
            'code'    => $code,
            'context' => $context,
            'binding' => $binding,
            'args'    => $args,
        ];

        // Serialize once for the signature
        $data = serialize($payloadData);
        $signature = hash_hmac('sha256', $data, $this->getSecretKey());

        // Return the array that PHP will native serialize
        return [
            'hash' => $signature,
            'data' => $data,
        ];
    }

    /**
     * Unserialize and verify integrity
     *
     * @param array $data The restored data array.
     */
    public function __unserialize(array $data): void
    {
        // Validate Structure
        if (!isset($data['hash'], $data['data'])) {
            throw new ActionsException('Invalid serialized closure structure');
        }

        // Security Check: Verify HMAC Signature
        if (!hash_equals(hash_hmac('sha256', $data['data'], $this->getSecretKey()), $data['hash'])) {
            throw new ActionsException('Closure security signature mismatch.');
        }

        $payload = unserialize($data['data']);

        // Unwrap context and args
        $context = $this->unwrapClosures($payload['context']);
        $this->args = $this->unwrapClosures($payload['args'] ?? []);

        // Extract variables into local scope
        // This makes the variables available to the closure when we eval() it.
        extract($context, EXTR_OVERWRITE | EXTR_REFS);

        // Reconstruct Closure
        // We wrap the code in "return ... ;" so eval passes the object back to us.
        $closure = @eval("return " . $payload['code'] . ";");

        if (!$closure instanceof Closure) {
            throw new ActionsException('Failed to reconstruct closure.');
        }

        $this->closure = $closure;
    }

    /**
     * Recursively wraps native Closures with this class.
     */
    protected function wrapClosures(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Closure) {
                $data[$key] = new self($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->wrapClosures($value);
            }
        }
        return $data;
    }

    /**
     * Recursively unwraps instances of this class back to native Closures.
     */
    protected function unwrapClosures(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof self) {
                $data[$key] = $value->getClosure();
            } elseif (is_array($value)) {
                $data[$key] = $this->unwrapClosures($value);
            }
        }
        return $data;
    }

    /**
     * Extracts source code using reflection.
     */
    protected function extractCode(ReflectionFunction $reflection): string
    {
        $fileName = $reflection->getFileName();

        if (empty($fileName) or !file_exists($fileName)) {
            throw new ActionsException('Cannot serialize eval() closures.');
        }

        $startLine = $reflection->getStartLine() - 1;
        $length = $reflection->getEndLine() - $startLine;
        $source = file($fileName);
        $body = implode("", array_slice($source, $startLine, $length));

        $tokens = token_get_all("<?php " . $body);
        $state = 'start';
        $balance = 0;
        $code = '';

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $type = is_array($token) ? $token[0] : null;

            // Skip comments and PHP tags
            if ($type === T_COMMENT || $type === T_DOC_COMMENT || $type === T_OPEN_TAG) {
                continue;
            }

            if ($state === 'start') {
                if ($type === T_FUNCTION || $type === T_FN) {
                    $state = 'recording';
                    $code .= $text;
                }
            } elseif ($state === 'recording') {
                $code .= $text;

                // Handle Balance
                if (in_array($text, ['{', '[', '(']) || $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $balance++;
                } elseif (in_array($text, ['}', ']', ')'])) {
                    $balance--;
                }

                // Stop Conditions
                if ($balance <= 0) {
                    // 1. Brace-style function end "}"
                    if ($text === '}' && $balance === 0) {
                        break;
                    }

                    // 2. Statement end ";" or Argument separator ","
                    if (($text === ';' || $text === ',') && $balance === 0) {
                        $code = substr($code, 0, -1); // Strip terminator
                        break;
                    }

                    // 3. Parenthesis closure of parent function ")"
                    // Only break if balance goes NEGATIVE (meaning it closed a parent paren)
                    if ($text === ')' && $balance < 0) {
                        $code = substr($code, 0, -1); // Strip terminator
                        break;
                    }
                }
            }
        }

        return trim($code);
    }

    /**
     * Helper to get the key consistently in both construct/serialize and unserialize phases.
     */
    protected function getSecretKey(): string
    {
        // Try Environment
        $key = getenv('encryption.key') ? : ($_ENV['encryption.key'] ?? null);

        // Try CodeIgniter Config (Fallback)
        if (!$key and function_exists('config')) {
            $config = config('Encryption');
            $key = $config->key ?? null;
        }

        if (!$key) {
            throw new ActionsException('Encryption key missing. Set "encryption.key" in .env');
        }

        // Handle CI4 Hex Keys (hex2bin:)
        if (str_starts_with($key, 'hex2bin:')) {
            $key = hex2bin(substr($key, 8));
        }

        return (string) $key;
    }
}