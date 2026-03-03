<?php

namespace Fleetbase\Services;

use Fleetbase\Models\Template;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * TemplateRenderService
 *
 * Responsible for rendering a Template model into HTML or PDF output.
 *
 * Rendering pipeline:
 *   1. Build global/ambient context (company, user, date/time).
 *   2. Build primary subject context from the passed Eloquent model.
 *   3. Execute any TemplateQuery data sources and add their results as iterable collections.
 *   4. Merge all contexts into a single flat variable map.
 *   5. Evaluate formula expressions: [{ expression }]
 *   6. Process iteration blocks: {{#each variable}} ... {{/each}}
 *   7. Substitute remaining scalar variables: {namespace.property}
 *   8. Render the final HTML from the template content (elements → HTML).
 *   9. Optionally pass through spatie/laravel-pdf for PDF output.
 */
class TemplateRenderService
{
    /**
     * Registry of context type schemas.
     * Each entry maps a context_type slug to metadata about its available variables.
     *
     * Extensions (e.g. Ledger, FleetOps) can register additional context types
     * by calling TemplateRenderService::registerContextType().
     */
    protected static array $contextTypes = [
        'generic' => [
            'label'       => 'Generic',
            'description' => 'No primary subject — only global variables are available.',
            'model'       => null,
            'variables'   => [],
        ],
    ];

    /**
     * Register a context type so the frontend variable picker knows about it.
     *
     * @param string $slug        e.g. 'invoice', 'order', 'shipping_label'
     * @param array  $definition  Keys: label, description, model (FQCN), variables (array of { name, path, type, description })
     */
    public static function registerContextType(string $slug, array $definition): void
    {
        static::$contextTypes[$slug] = $definition;
    }

    /**
     * Return all registered context type schemas.
     */
    public function getContextSchemas(): array
    {
        // Always prepend the global variables that are available in every context
        $globalVariables = $this->getGlobalVariableSchema();

        return array_map(function ($definition) use ($globalVariables) {
            $definition['global_variables'] = $globalVariables;

            return $definition;
        }, static::$contextTypes);
    }

    /**
     * Render a template to an HTML string.
     *
     * @param Template   $template The template to render.
     * @param Model|null $subject  The primary data subject (e.g. an Invoice or Order model instance).
     */
    public function renderToHtml(Template $template, ?Model $subject = null): string
    {
        $context = $this->buildContext($template, $subject);
        $html    = $this->buildHtmlFromContent($template);
        $html    = $this->processIterationBlocks($html, $context);
        $html    = $this->evaluateFormulas($html, $context);
        $html    = $this->substituteVariables($html, $context);

        return $this->wrapInDocument($html, $template);
    }

    /**
     * Render a template to a PDF response using spatie/laravel-pdf.
     *
     * @param Template   $template
     * @param Model|null $subject
     *
     * @return \Spatie\LaravelPdf\PdfBuilder
     */
    public function renderToPdf(Template $template, ?Model $subject = null)
    {
        $html = $this->renderToHtml($template, $subject);

        // spatie/laravel-pdf — driver resolved from config (dompdf by default)
        return \Spatie\LaravelPdf\Facades\Pdf::html($html)
            ->paperSize($template->width, $template->height, $template->unit)
            ->margins(
                data_get($template->margins, 'top', 10),
                data_get($template->margins, 'right', 10),
                data_get($template->margins, 'bottom', 10),
                data_get($template->margins, 'left', 10),
                $template->unit
            );
    }

    // -------------------------------------------------------------------------
    // Context building
    // -------------------------------------------------------------------------

    /**
     * Build the full merged variable context for a render operation.
     *
     * Context tiers (later tiers override earlier on conflict):
     *   1. Global / ambient (company, user, date)
     *   2. Primary subject (the passed $subject model)
     *   3. Query-based collections (from TemplateQuery records)
     */
    protected function buildContext(Template $template, ?Model $subject = null): array
    {
        $context = [];

        // Tier 1: Global ambient context
        $context = array_merge($context, $this->buildGlobalContext());

        // Tier 2: Primary subject context
        if ($subject !== null) {
            $subjectKey = $template->context_type !== 'generic' ? $template->context_type : $this->guessContextKey($subject);
            $context[$subjectKey] = $this->modelToArray($subject);
        }

        // Tier 3: Query-based collections
        $template->loadMissing('queries');
        foreach ($template->queries as $templateQuery) {
            $results = $templateQuery->execute();
            $context[$templateQuery->variable_name] = $results->map(fn ($item) => $this->modelToArray($item))->toArray();
        }

        return $context;
    }

    /**
     * Build the global/ambient context that is always available in every template.
     *
     * Variables:
     *   {now}              — current ISO 8601 datetime
     *   {today}            — current date (Y-m-d)
     *   {time}             — current time (H:i:s)
     *   {year}             — current 4-digit year
     *   {company.*}        — current session company attributes
     *   {user.*}           — current authenticated user attributes
     */
    protected function buildGlobalContext(): array
    {
        $now = Carbon::now();

        $context = [
            'now'   => $now->toIso8601String(),
            'today' => $now->toDateString(),
            'time'  => $now->toTimeString(),
            'year'  => $now->year,
        ];

        // Company context from session
        $companyUuid = Session::get('company');
        if ($companyUuid) {
            $company = \Fleetbase\Models\Company::where('uuid', $companyUuid)->first();
            if ($company) {
                $context['company'] = $this->modelToArray($company);
            }
        }

        // Authenticated user context
        $user = Auth::user();
        if ($user) {
            $context['user'] = $this->modelToArray($user);
        }

        return $context;
    }

    /**
     * Return the schema of global variables for the frontend variable picker.
     */
    protected function getGlobalVariableSchema(): array
    {
        return [
            ['name' => 'Current Date & Time', 'path' => 'now',          'type' => 'string',  'description' => 'ISO 8601 timestamp of the render time.'],
            ['name' => 'Today\'s Date',        'path' => 'today',        'type' => 'string',  'description' => 'Current date in Y-m-d format.'],
            ['name' => 'Current Time',         'path' => 'time',         'type' => 'string',  'description' => 'Current time in H:i:s format.'],
            ['name' => 'Current Year',         'path' => 'year',         'type' => 'integer', 'description' => 'Current 4-digit year.'],
            ['name' => 'Company Name',         'path' => 'company.name', 'type' => 'string',  'description' => 'Name of the current organisation.'],
            ['name' => 'Company Email',        'path' => 'company.email','type' => 'string',  'description' => 'Primary email of the current organisation.'],
            ['name' => 'Company Phone',        'path' => 'company.phone','type' => 'string',  'description' => 'Phone number of the current organisation.'],
            ['name' => 'Company Address',      'path' => 'company.address', 'type' => 'string', 'description' => 'Street address of the current organisation.'],
            ['name' => 'User Name',            'path' => 'user.name',    'type' => 'string',  'description' => 'Full name of the authenticated user.'],
            ['name' => 'User Email',           'path' => 'user.email',   'type' => 'string',  'description' => 'Email address of the authenticated user.'],
        ];
    }

    // -------------------------------------------------------------------------
    // HTML generation from template content
    // -------------------------------------------------------------------------

    /**
     * Convert the template's content (array of element objects) into an HTML string.
     * Each element is absolutely positioned within the canvas.
     */
    protected function buildHtmlFromContent(Template $template): string
    {
        $elements = $template->content ?? [];
        $html     = '';

        foreach ($elements as $element) {
            $html .= $this->renderElement($element);
        }

        return $html;
    }

    /**
     * Render a single element object to its HTML representation.
     *
     * Element object shape:
     * {
     *   id, type, x, y, width, height, rotation,
     *   content, styles, attributes
     * }
     */
    protected function renderElement(array $element): string
    {
        $type     = data_get($element, 'type', 'text');
        $x        = data_get($element, 'x', 0);
        $y        = data_get($element, 'y', 0);
        $width    = data_get($element, 'width', 100);
        $height   = data_get($element, 'height', 'auto');
        $rotation = data_get($element, 'rotation', 0);
        $styles   = data_get($element, 'styles', []);
        $content  = data_get($element, 'content', '');

        // Build inline style string
        $styleStr = $this->buildInlineStyle(array_merge([
            'position'  => 'absolute',
            'left'      => $x . 'px',
            'top'       => $y . 'px',
            'width'     => is_numeric($width) ? $width . 'px' : $width,
            'height'    => is_numeric($height) ? $height . 'px' : $height,
            'transform' => $rotation ? "rotate({$rotation}deg)" : null,
        ], $styles));

        switch ($type) {
            case 'text':
            case 'heading':
            case 'paragraph':
                return "<div style=\"{$styleStr}\">{$content}</div>\n";

            case 'image':
                $src = data_get($element, 'src', '');

                return "<img src=\"{$src}\" style=\"{$styleStr}\" alt=\"\" />\n";

            case 'table':
                return $this->renderTableElement($element, $styleStr);

            case 'line':
                $borderStyle = $this->buildInlineStyle([
                    'position'     => 'absolute',
                    'left'         => $x . 'px',
                    'top'          => $y . 'px',
                    'width'        => is_numeric($width) ? $width . 'px' : $width,
                    'border-top'   => data_get($styles, 'borderTop', '1px solid #000'),
                ]);

                return "<hr style=\"{$borderStyle}\" />\n";

            case 'rectangle':
            case 'shape':
                return "<div style=\"{$styleStr}\"></div>\n";

            case 'qr_code':
                // QR code is rendered as an img with a data URL generated at render time
                $value = data_get($element, 'value', '');

                return "<div style=\"{$styleStr}\" data-qr=\"{$value}\"></div>\n";

            case 'barcode':
                $value = data_get($element, 'value', '');

                return "<div style=\"{$styleStr}\" data-barcode=\"{$value}\"></div>\n";

            default:
                return "<div style=\"{$styleStr}\">{$content}</div>\n";
        }
    }

    /**
     * Render a table element from its column/row definitions.
     *
     * Table element shape:
     * {
     *   columns: [{ label, key, width }],
     *   rows: [ {key: value, ...} ]  OR  data_source: 'variable_name'
     * }
     */
    protected function renderTableElement(array $element, string $styleStr): string
    {
        $columns    = data_get($element, 'columns', []);
        $rows       = data_get($element, 'rows', []);
        $dataSource = data_get($element, 'data_source'); // variable name for dynamic rows

        $tableStyle = $this->buildInlineStyle([
            'width'           => '100%',
            'border-collapse' => 'collapse',
        ]);

        $html = "<table style=\"{$styleStr}{$tableStyle}\">\n<thead>\n<tr>\n";

        foreach ($columns as $col) {
            $label      = data_get($col, 'label', '');
            $colWidth   = data_get($col, 'width', 'auto');
            $colStyle   = $colWidth !== 'auto' ? " style=\"width:{$colWidth}\"" : '';
            $html      .= "<th{$colStyle}>{$label}</th>\n";
        }

        $html .= "</tr>\n</thead>\n<tbody>\n";

        // If the table has a dynamic data source, emit an each block placeholder
        // that will be resolved during the iteration pass
        if ($dataSource) {
            $html .= "{{#each {$dataSource}}}\n<tr>\n";
            foreach ($columns as $col) {
                $key   = data_get($col, 'key', '');
                $html .= "<td>{this.{$key}}</td>\n";
            }
            $html .= "</tr>\n{{/each}}\n";
        } else {
            foreach ($rows as $row) {
                $html .= "<tr>\n";
                foreach ($columns as $col) {
                    $key   = data_get($col, 'key', '');
                    $value = data_get($row, $key, '');
                    $html .= "<td>{$value}</td>\n";
                }
                $html .= "</tr>\n";
            }
        }

        $html .= "</tbody>\n</table>\n";

        return $html;
    }

    // -------------------------------------------------------------------------
    // Rendering passes
    // -------------------------------------------------------------------------

    /**
     * Pass 1: Process {{#each variable}} ... {{/each}} iteration blocks.
     *
     * Within a block, {this.property} refers to the current iteration item.
     * Nested each blocks are not supported in this implementation.
     */
    protected function processIterationBlocks(string $html, array $context): string
    {
        $pattern = '/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s';

        return preg_replace_callback($pattern, function (array $matches) use ($context) {
            $variableName = $matches[1];
            $blockContent = $matches[2];
            $collection   = data_get($context, $variableName, []);

            if (empty($collection) || !is_array($collection)) {
                return '';
            }

            $output = '';
            $total  = count($collection);

            foreach ($collection as $index => $item) {
                $itemHtml = $blockContent;

                // Replace {this.property} with the item's values
                $itemHtml = preg_replace_callback('/\{this\.([^}]+)\}/', function ($m) use ($item) {
                    return data_get($item, $m[1], '');
                }, $itemHtml);

                // Replace {loop.index}, {loop.first}, {loop.last}
                $itemHtml = str_replace('{loop.index}', $index, $itemHtml);
                $itemHtml = str_replace('{loop.first}', $index === 0 ? 'true' : 'false', $itemHtml);
                $itemHtml = str_replace('{loop.last}', $index === $total - 1 ? 'true' : 'false', $itemHtml);

                $output .= $itemHtml;
            }

            return $output;
        }, $html);
    }

    /**
     * Pass 2: Evaluate formula expressions.
     *
     * Syntax: [{ expression }]
     * Example: [{ {invoice.subtotal} * 1.1 }]
     *
     * The expression is first variable-substituted (scalars only), then
     * evaluated using a safe arithmetic evaluator.
     */
    protected function evaluateFormulas(string $html, array $context): string
    {
        $pattern = '/\[\{\s*(.*?)\s*\}\]/s';

        return preg_replace_callback($pattern, function (array $matches) use ($context) {
            $expression = $matches[1];

            // Substitute variables within the expression first
            $expression = preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($context) {
                $value = data_get($context, $m[1]);

                return is_numeric($value) ? $value : (is_string($value) ? $value : '0');
            }, $expression);

            return $this->evaluateArithmetic($expression);
        }, $html);
    }

    /**
     * Pass 3: Substitute all remaining scalar variables.
     *
     * Syntax: {namespace.property} or {scalar_key}
     * Supports dot-notation for nested access.
     */
    protected function substituteVariables(string $html, array $context): string
    {
        return preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function (array $matches) use ($context) {
            $path  = $matches[1];
            $value = data_get($context, $path);

            if (is_array($value) || is_object($value)) {
                return ''; // Skip non-scalar values
            }

            return $value ?? '';
        }, $html);
    }

    // -------------------------------------------------------------------------
    // Formula evaluation
    // -------------------------------------------------------------------------

    /**
     * Safely evaluate a simple arithmetic expression string.
     *
     * Uses a recursive descent parser to avoid eval() and support:
     *   +, -, *, /, ^ (power), parentheses, and numeric literals.
     *
     * If mossadal/math-parser is available it will be used instead for
     * full function support (sqrt, abs, round, etc.).
     */
    protected function evaluateArithmetic(string $expression): string
    {
        // Prefer mossadal/math-parser if installed
        if (class_exists(\MathParser\StdMathParser::class)) {
            try {
                $parser    = new \MathParser\StdMathParser();
                $evaluator = new \MathParser\Interpreting\Evaluator();
                $ast       = $parser->parse($expression);
                $result    = $ast->accept($evaluator);

                return is_float($result) ? rtrim(rtrim(number_format($result, 10, '.', ''), '0'), '.') : (string) $result;
            } catch (\Throwable $e) {
                return '#ERR';
            }
        }

        // Fallback: simple recursive descent evaluator for +, -, *, /, ()
        try {
            return (string) $this->parseExpression(trim($expression));
        } catch (\Throwable $e) {
            return '#ERR';
        }
    }

    /**
     * Simple recursive descent arithmetic parser (fallback).
     * Supports: +, -, *, /, unary minus, parentheses, integer and float literals.
     */
    protected function parseExpression(string $expr): float
    {
        // Remove all whitespace
        $expr = preg_replace('/\s+/', '', $expr);
        $pos  = 0;

        return $this->parseAddSub($expr, $pos);
    }

    protected function parseAddSub(string $expr, int &$pos): float
    {
        $left = $this->parseMulDiv($expr, $pos);

        while ($pos < strlen($expr) && in_array($expr[$pos], ['+', '-'])) {
            $op = $expr[$pos++];
            $right = $this->parseMulDiv($expr, $pos);
            $left  = $op === '+' ? $left + $right : $left - $right;
        }

        return $left;
    }

    protected function parseMulDiv(string $expr, int &$pos): float
    {
        $left = $this->parseUnary($expr, $pos);

        while ($pos < strlen($expr) && in_array($expr[$pos], ['*', '/'])) {
            $op    = $expr[$pos++];
            $right = $this->parseUnary($expr, $pos);
            $left  = $op === '*' ? $left * $right : ($right != 0 ? $left / $right : 0);
        }

        return $left;
    }

    protected function parseUnary(string $expr, int &$pos): float
    {
        if ($pos < strlen($expr) && $expr[$pos] === '-') {
            $pos++;

            return -$this->parsePrimary($expr, $pos);
        }

        return $this->parsePrimary($expr, $pos);
    }

    protected function parsePrimary(string $expr, int &$pos): float
    {
        if ($pos < strlen($expr) && $expr[$pos] === '(') {
            $pos++; // consume '('
            $value = $this->parseAddSub($expr, $pos);
            if ($pos < strlen($expr) && $expr[$pos] === ')') {
                $pos++; // consume ')'
            }

            return $value;
        }

        // Parse numeric literal
        preg_match('/^-?[0-9]*\.?[0-9]+/', substr($expr, $pos), $m);
        if (!empty($m)) {
            $pos += strlen($m[0]);

            return (float) $m[0];
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // HTML document wrapping
    // -------------------------------------------------------------------------

    /**
     * Wrap the rendered element HTML in a full document shell with canvas dimensions.
     */
    protected function wrapInDocument(string $bodyHtml, Template $template): string
    {
        $bgColor = $template->background_color ?? '#ffffff';
        $width   = $template->width;
        $height  = $template->height;
        $unit    = $template->unit;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f0f0f0; }
  .canvas {
    position: relative;
    width: {$width}{$unit};
    height: {$height}{$unit};
    background: {$bgColor};
    overflow: hidden;
  }
</style>
</head>
<body>
<div class="canvas">
{$bodyHtml}
</div>
</body>
</html>
HTML;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Convert a model to a plain array suitable for use as a variable context.
     * Loads visible attributes and appended attributes.
     */
    protected function modelToArray(Model $model): array
    {
        return $model->toArray();
    }

    /**
     * Guess the context key (namespace) for a model instance from its class name.
     * e.g. App\Models\Invoice → 'invoice'
     */
    protected function guessContextKey(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    /**
     * Convert an associative array of CSS properties to an inline style string.
     */
    protected function buildInlineStyle(array $styles): string
    {
        $parts = [];
        foreach ($styles as $property => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            // Convert camelCase to kebab-case
            $property = Str::kebab($property);
            $parts[]  = "{$property}: {$value}";
        }

        return implode('; ', $parts) . (count($parts) ? ';' : '');
    }
}
