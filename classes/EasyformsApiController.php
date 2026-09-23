<?php

declare(strict_types=1);

namespace Grav\Plugin\Easyform;

use Grav\Common\Utils;
use Grav\Plugin\Api\Controllers\AbstractApiController;
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
