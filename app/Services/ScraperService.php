<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class ScraperService
{
    private const MAX_HTML_BYTES = 5242880;
    
    public function fetchHtml(string $url): Response
    {
        for ($redirectCount = 0; $redirectCount <= 5; $redirectCount++) {
            $this->assertPublicUrl($url);

            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ScraperTracker/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.8',
                ])
                ->get($url);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            $location = $response->header('Location');
            if ($redirectCount === 5 || ! is_string($location) || $location === '') {
                throw new RuntimeException('La página excedió el límite de redirecciones permitidas.');
            }

            $url = (string) UriResolver::resolve(
                new Uri($url),
                new Uri($location),
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException('El sitio respondió con HTTP '.$response->status().'.');
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml+xml')) {
            throw new RuntimeException('La URL no devolvió un documento HTML.');
        }

        if (strlen($response->body()) > self::MAX_HTML_BYTES) {
            throw new RuntimeException('El HTML supera el límite permitido de 500 KB.');
        }

        return $response;
    }

    public function detectedSelectors(string $html): array
    {
        $candidates = [
            'titulo' => [
                ['selector' => 'h1', 'atributo' => null],
                ['selector' => '[itemprop="name"]', 'atributo' => null],
                ['selector' => 'meta[property="og:title"]', 'atributo' => 'content'],
            ],
            'precioOferta' => [
                ['selector' => '[itemprop="price"]', 'atributo' => 'content'],
                ['selector' => 'meta[property="product:price:amount"]', 'atributo' => 'content'],
                ['selector' => '.price', 'atributo' => null],
                ['selector' => '[class*="price"]', 'atributo' => null],
            ],
            'imagen' => [
                ['selector' => 'meta[property="og:image"]', 'atributo' => 'content'],
                ['selector' => 'img[itemprop="image"]', 'atributo' => 'src'],
                ['selector' => 'img', 'atributo' => 'src'],
            ],
            'descripcion' => [
                ['selector' => 'meta[property="og:description"]', 'atributo' => 'content'],
                ['selector' => '[itemprop="description"]', 'atributo' => null],
                ['selector' => '.product-description', 'atributo' => null],
            ],
        ];

        $labels = [
            'titulo' => 'Título',
            'precioOferta' => 'Precio',
            'imagen' => 'Imagen',
            'descripcion' => 'Descripción',
        ];
        $detected = [];

        foreach ($candidates as $field => $options) {
            $detected[$field] = [
                'etiqueta' => $labels[$field],
                'selector' => '',
                'atributo' => null,
                'valor' => '',
            ];

            foreach ($options as $option) {
                $value = $this->selectValue($html, $option['selector'], $option['atributo']);
                if ($value !== '') {
                    $detected[$field] = [
                        'etiqueta' => $labels[$field],
                        ...$option,
                        'valor' => $value,
                    ];
                    break;
                }
            }
        }

        return $detected;
    }

    public function extractFields(string $html, array $selectors): array
    {
        $values = [];

        foreach ($selectors as $field => $definition) {
            if (! is_array($definition) || ! is_string($definition['selector'] ?? null)) {
                continue;
            }

            if (trim($definition['selector']) === '') {
                $values[$field] = '';

                continue;
            }

            $attribute = $definition['atributo'] ?? null;
            $values[$field] = $this->selectValue(
                $html,
                $definition['selector'],
                is_string($attribute) && $attribute !== '' ? $attribute : null,
            );
        }

        return $values;
    }

    public function metadata(string $html, string $url): array
    {
        $document = $this->loadDocument($html);
        $xpath = new \DOMXPath($document);

        return [
            'sitio' => parse_url($url, PHP_URL_HOST),
            'tituloDocumento' => trim($xpath->evaluate('string(//title)')),
            'bytesHtml' => strlen($html),
        ];
    }

    private function selectValue(string $html, string $selector, ?string $attribute): string
    {
        try {
            $xpathQuery = $this->cssToXpath($selector);
            $document = $this->loadDocument($html);
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query($xpathQuery);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return '';
        }

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $node = $nodes->item(0);
        if ($attribute !== null) {
            if (! preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/', $attribute)) {
                throw new InvalidArgumentException('El atributo CSS indicado no es válido.');
            }

            $value = $node instanceof \DOMElement ? $node->getAttribute($attribute) : '';
        } else {
            $value = $node->textContent;
        }

        return trim(html_entity_decode(preg_replace('/\s+/', ' ', $value) ?? $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function loadDocument(string $html): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function cssToXpath(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '' || strlen($selector) > 256) {
            throw new InvalidArgumentException('Ingresa un selector CSS válido (máximo 256 caracteres).');
        }

        $groups = $this->splitSelectorGroups($selector);
        $queries = [];
        foreach ($groups as $group) {
            $queries[] = $this->selectorGroupToXpath($group);
        }

        return implode(' | ', $queries);
    }

    private function splitSelectorGroups(string $selector): array
    {
        $groups = [];
        $start = 0;
        $bracketDepth = 0;
        $quote = null;

        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $character = $selector[$index];
            if ($quote !== null) {
                if ($character === $quote && ($index === 0 || $selector[$index - 1] !== '\\')) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '[') {
                $bracketDepth++;
            } elseif ($character === ']') {
                $bracketDepth--;
            } elseif ($character === ',' && $bracketDepth === 0) {
                $groups[] = trim(substr($selector, $start, $index - $start));
                $start = $index + 1;
            }
        }

        $groups[] = trim(substr($selector, $start));

        return $groups;
    }

    private function selectorGroupToXpath(string $selector): string
    {
        preg_match_all('/\[[^\]]+\]|[^\s>]+|>/', trim($selector), $matches);
        $tokens = $matches[0];
        if ($tokens === []) {
            throw new InvalidArgumentException('El selector CSS no es válido.');
        }

        $xpath = '';
        $combinator = ' ';
        foreach ($tokens as $token) {
            if ($token === '>') {
                $combinator = '>';

                continue;
            }

            $step = $this->cssCompoundToXpath($token);
            $xpath .= $combinator === '>' ? '/'.$step : '//'.$step;
            $combinator = ' ';
        }

        return $xpath;
    }

    private function cssCompoundToXpath(string $compound): string
    {
        preg_match('/^(\*|[a-zA-Z][a-zA-Z0-9-]*)?/', $compound, $tagMatch);
        $tag = $tagMatch[0] !== '' ? strtolower($tagMatch[0]) : '*';
        $offset = strlen($tagMatch[0]);
        $predicates = [];
        $length = strlen($compound);

        while ($offset < $length) {
            $remaining = substr($compound, $offset);
            if (preg_match('/^#([a-zA-Z_][a-zA-Z0-9_-]*)/', $remaining, $match)) {
                $predicates[] = '@id='.$this->xpathLiteral($match[1]);
            } elseif (preg_match('/^\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $remaining, $match)) {
                $predicates[] = 'contains(concat(" ", normalize-space(@class), " "), '.$this->xpathLiteral(' '.$match[1].' ').')';
            } elseif (preg_match('/^\[([a-zA-Z_:][a-zA-Z0-9_.:-]*)(?:\s*(=|\*=|\^=|\$=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]]+)))?\]/', $remaining, $match)) {
                $attribute = '@'.$match[1];
                $operator = $match[2] ?? '';
                if ($operator === '') {
                    $predicates[] = $attribute;
                } else {
                    $value = trim($match[3] !== '' ? $match[3] : ($match[4] !== '' ? $match[4] : ($match[5] ?? '')));
                    $literal = $this->xpathLiteral($value);
                    $predicates[] = match ($operator) {
                        '=' => $attribute.'='.$literal,
                        '*=' => 'contains('.$attribute.','.$literal.')',
                        '^=' => 'starts-with('.$attribute.','.$literal.')',
                        '$=' => 'substring('.$attribute.', string-length('.$attribute.') - string-length('.$literal.') + 1)='.$literal,
                    };
                }
            } else {
                throw new InvalidArgumentException('Selector CSS no soportado: '.$compound);
            }

            preg_match('/^(?:#[a-zA-Z_][a-zA-Z0-9_-]*|\.[a-zA-Z_][a-zA-Z0-9_-]*|\[[^\]]+\])/', $remaining, $consumed);
            if (! isset($consumed[0])) {
                throw new InvalidArgumentException('Selector CSS no válido: '.$compound);
            }
            $offset += strlen($consumed[0]);
        }

        return $tag.($predicates === [] ? '' : '['.implode(' and ', $predicates).']');
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }
        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        return "concat('".str_replace("'", "', \"'\", '", $value)."')";
    }

    private function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Ingresa una URL pública válida que use HTTP o HTTPS.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_values(array_unique(array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));

        if ($addresses === []) {
            throw new InvalidArgumentException('No fue posible resolver el dominio de la URL.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Por seguridad, solo se permiten dominios que resuelvan a direcciones IP públicas.');
            }
        }
    }
}
