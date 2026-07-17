<?php

use Fleetbase\Models\Template;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class TemplateRenderServiceSessionFake
{
    public function get(string $key, mixed $default = null): mixed
    {
        return session($key, $default);
    }
}

class TemplateRenderServiceAuthFake
{
    public function user(): mixed
    {
        return null;
    }
}

class TemplateRenderServiceOrder extends Model
{
    public function toArray(): array
    {
        return [
            'number' => 'T-001',
            'customer' => [
                'name' => 'Acme Logistics',
            ],
            'subtotal' => 100,
            'line_items' => [
                ['name' => 'Freight', 'amount' => 75],
                ['name' => 'Handling', 'amount' => 25],
            ],
        ];
    }
}

function template_render_service_container(): void
{
    $container = bind_test_container([
        'fleetbase.template_query_models' => [],
        'fleetbase.template_global_query_models' => [],
    ]);

    session()->flush();
    $container->instance('session', new TemplateRenderServiceSessionFake());
    $container->instance('auth', new TemplateRenderServiceAuthFake());
    Facade::clearResolvedInstance('session');
    Facade::clearResolvedInstance('auth');
}

function template_render_service_template(array $content): Template
{
    $template = new Template([
        'context_type' => 'order',
        'width' => 210,
        'height' => 297,
        'unit' => 'mm',
        'background_color' => '#fafafa',
        'content' => $content,
    ]);
    $template->setRelation('queries', new Collection());

    return $template;
}

test('template render service registers context schemas and query model allowlists', function () {
    template_render_service_container();

    TemplateRenderService::registerContextType('coverage_order', [
        'label' => 'Coverage Order',
        'description' => 'Order context used by coverage tests.',
        'model' => TemplateRenderServiceOrder::class,
        'variables' => [
            ['name' => 'Order Number', 'path' => 'coverage_order.number', 'type' => 'string'],
        ],
    ]);

    $service = new TemplateRenderService();
    $schemas = $service->getContextSchemas();

    expect($schemas['coverage_order']['label'])->toBe('Coverage Order')
        ->and($schemas['coverage_order']['global_variables'])->toContain([
            'name' => 'Current Year',
            'path' => 'year',
            'type' => 'integer',
            'description' => 'Current 4-digit year.',
        ])
        ->and(TemplateRenderService::getTemplateQueryModels())->toContain(TemplateRenderServiceOrder::class)
        ->and(TemplateRenderService::isTemplateQueryModelAllowed(TemplateRenderServiceOrder::class))->toBeTrue()
        ->and(TemplateRenderService::isTemplateQueryModelAllowed(Template::class))->toBeFalse();
});

test('template render service renders variables formulas loops tables and document wrapper', function () {
    template_render_service_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:34:56'));

    $template = template_render_service_template([
        [
            'type' => 'heading',
            'x' => 10,
            'y' => 20,
            'width' => 180,
            'height' => 24,
            'styles' => ['fontSize' => '18px', 'fontWeight' => '700'],
            'content' => 'Order {order.number} for {order.customer.name} on {today}',
        ],
        [
            'type' => 'text',
            'x' => 10,
            'y' => 50,
            'width' => 180,
            'content' => 'Taxed total: [{ {order.subtotal} * 1.1 }]',
        ],
        [
            'type' => 'paragraph',
            'x' => 10,
            'y' => 80,
            'width' => 180,
            'content' => '{{#each order.line_items}}{loop.index}:{this.name}:{this.amount}:{loop.first}:{loop.last};{{/each}}',
        ],
        [
            'type' => 'table',
            'x' => 10,
            'y' => 120,
            'width' => 180,
            'columns' => [
                ['label' => 'Item', 'key' => 'name', 'width' => '70%'],
                ['label' => 'Amount', 'key' => 'amount'],
            ],
            'data_source' => 'order.line_items',
        ],
    ]);

    $html = (new TemplateRenderService())->renderToHtml($template, new TemplateRenderServiceOrder());

    expect($html)->toContain('<!DOCTYPE html>')
        ->and($html)->toContain('width: 210mm;')
        ->and($html)->toContain('height: 297mm;')
        ->and($html)->toContain('background: #fafafa;')
        ->and($html)->toContain('font-size: 18px; font-weight: 700;')
        ->and($html)->toContain('Order T-001 for Acme Logistics on 2026-07-17')
        ->and($html)->toContain('Taxed total: 110')
        ->and($html)->toContain('0:Freight:75:true:false;1:Handling:25:false:true;')
        ->and($html)->toContain('<th style="width:70%">Item</th>')
        ->and($html)->toContain('<td>Freight</td>')
        ->and($html)->toContain('<td>25</td>');

    Carbon::setTestNow();
});
