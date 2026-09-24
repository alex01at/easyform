<?php

declare(strict_types=1);

namespace Grav\Plugin\Easyform;

use Grav\Common\Utils;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin2 (API-driven admin) backend for the easyform plugin page.
 * Registered via onApiRegisterRoutes() in EasyformPlugin.
 *
 * admin2's plugin-page route only supports a single URL segment, and its
 * blueprint-resolution endpoint (BlueprintController::pluginPageBlueprint())
 * does a raw filesystem lookup on that segment as a real plugin slug — there
 * is no per-page override, so a separate synthetic page per form (as
 * admin-classic uses) is impossible there. Instead every form is managed as
 * one row in a single repeatable list field, all under the one real plugin
 * page at /plugin/easyform, backed by GET/PATCH /easyform here.
 */
final class EasyformsApiController extends AbstractApiController
{
    private const PERMISSION = 'admin.easyform';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        return ApiResponse::create($this->buildPayload());
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $body = $this->getRequestBody($request);

        if (Utils::isPositive($body['apply_update'] ?? false)) {
            // Replacing the plugin's own code on disk is restricted to super
            // admins, unlike editing forms.
            $this->requireSuper($request);

            $result = EasyformsUpdater::applyUpdate();
            if (!$result['success']) {
                throw new ValidationException($result['message']);
            }

            return ApiResponse::create($this->buildPayload());
        }

        $forms = (array) ($body['forms'] ?? []);
        $result = EasyformsHelper::syncFromList($forms);

        if ($result['errors']) {
            throw new ValidationException(implode(' ', $result['errors']));
        }

        return ApiResponse::create($this->buildPayload());
    }

    /**
     * GET /easyform/_badge — live sidebar badge count: the number of
     * stored forms, consumed by admin2's badgeEndpoint mechanism. (A
     * pending-update indicator would be a less standard use of a nav
     * badge, and is already shown prominently on the page itself.)
     */
    public function badge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        return ApiResponse::create(['count' => count(EasyformsHelper::listForms())]);
    }

    /**
     * GET /easyform/export/{name} — raw CSV, not the usual JSON envelope.
     * The admin2 UI can't reach this via a plain link (its session is
     * stateless JWT, never a browsable cookie session — confirmed by
     * reading AuthController::token(), which explicitly restores whatever
     * front-end session existed before the API login rather than adopting
     * it), so the custom `easyform-submissions` field fetches this with the
     * same X-API-Token header admin2's own JS uses and turns the response
     * into a client-side download itself.
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $name = (string) $this->getRouteParam($request, 'name');
        if (!EasyformsHelper::exists($name)) {
            throw new NotFoundException("Form '{$name}' not found.");
        }

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '-submissions.csv"',
        ], EasyformsHelper::submissionsCsv($name));
    }

    /**
     * GET /easyform/preview/{name} — raw HTML, fetched and opened in a new
     * tab the same way export() is (see its docblock: admin2 has no
     * browsable session for a plain link to carry).
     *
     * EasyformsHelper::renderFormHtml() needs a real $grav['page'] (it builds
     * the Form against it), which a JSON API request doesn't set up on its
     * own the way normal page routing does. $pages->root() looked like the
     * obvious stand-in but isn't usable as-is: it has no backing content
     * file (so header() can't lazy-load one) and no parent chain (so
     * route() resolves to null), and Form::getAction() requires a non-null
     * route. A bare Page with slug/header/route all set explicitly sidesteps
     * both — the preview form doesn't read anything else page-specific.
     */
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $name = (string) $this->getRouteParam($request, 'name');
        if (!EasyformsHelper::isValidName($name)) {
            throw new NotFoundException("Form '{$name}' not found.");
        }

        $page = new \Grav\Common\Page\Page();
        $page->slug('easyform-preview');
        $page->route('/easyform-preview');
        $page->header(['title' => 'Preview']);
        unset($this->grav['page']);
        $this->grav['page'] = $page;

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ], EasyformsHelper::renderPreviewHtml($name));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(): array
    {
        $release = EasyformsUpdater::checkLatestRelease();

        return [
            'forms' => EasyformsHelper::listAllRaw(),
            'update_current_version' => $release['current'],
            'update_latest_version' => $release['latest'] ?? $release['current'],
            'update_notes' => $release['error'] ?? (string) ($release['body'] ?? ''),
            'apply_update' => false,
        ];
    }
}
