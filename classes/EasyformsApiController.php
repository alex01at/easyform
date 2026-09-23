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
 * Admin2 (API-driven admin) backend for the easyform blueprint page.
 * Registered via onApiRegisterRoutes() in EasyformPlugin.
 *
 * A GET/PATCH pair per form name, addressed through admin2's generic
 * "blueprint" plugin-page type (see onApiPluginPageInfo()). Deletion has no
 * dedicated admin2 action button in this implementation, so it rides along
 * on the save request via a `delete_requested` blueprint field instead.
 */
final class EasyformsApiController extends AbstractApiController
{
    private const PERMISSION = 'admin.easyform';

    /** Route sentinel for "no form yet" (not a valid form name: contains '_'). */
    private const NEW_SENTINEL = '_new';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $name = (string) $this->getRouteParam($request, 'name');

        if ($name === self::NEW_SENTINEL) {
            return ApiResponse::create(['name' => '']);
        }

        $data = EasyformsHelper::loadRaw($name) ?? ['name' => $name];

        return ApiResponse::create($data);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $routeName = (string) $this->getRouteParam($request, 'name');
        $body = $this->getRequestBody($request);

        $existingName = $routeName !== self::NEW_SENTINEL ? $routeName : null;

        $name = trim((string) ($body['name'] ?? $existingName ?? ''));

        // The name is the filename; once a form exists it cannot be renamed
        // through this endpoint (the field is only meaningful when creating).
        if ($existingName !== null) {
            $name = $existingName;
        }

        if (!EasyformsHelper::isValidName($name)) {
            throw new ValidationException(
                'Please enter a valid form name (lowercase letters, numbers and hyphens only).',
                [['field' => 'name', 'message' => 'Invalid form name.']]
            );
        }

        if ($existingName === null && EasyformsHelper::exists($name)) {
            throw new ValidationException(
                'A form with this name already exists.',
                [['field' => 'name', 'message' => 'Name already taken.']]
            );
        }

        if ($existingName !== null && Utils::isPositive($body['delete_requested'] ?? false)) {
            EasyformsHelper::delete($name);

            return ApiResponse::create(['name' => $name, 'deleted' => true]);
        }

        unset($body['delete_requested']);
        $body['name'] = $name;

        EasyformsHelper::save($name, $body);

        return ApiResponse::create($body);
    }

    /**
     * GET /easyform/_update — current vs. latest GitHub release, for the
     * admin2 "Update" blueprint page.
     */
    public function showUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $release = EasyformsUpdater::checkLatestRelease();

        return ApiResponse::create([
            'current_version' => $release['current'],
            'latest_version' => $release['latest'] ?? $release['current'],
            'release_notes' => $release['error'] ?? (string) ($release['body'] ?? ''),
        ]);
    }

    /**
     * PATCH /easyform/_update — downloads and installs the latest GitHub
     * release in place. Restricted to super admins: unlike the regular form
     * CRUD, this replaces the plugin's own code on disk.
     */
    public function applyUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        $result = EasyformsUpdater::applyUpdate();

        if (!$result['success']) {
            throw new ValidationException($result['message']);
        }

        return ApiResponse::create([
            'current_version' => $result['version'],
            'latest_version' => $result['version'],
            'release_notes' => $result['message'],
        ]);
    }

    /**
     * GET /easyform/_update/badge — live sidebar badge count (0 or 1),
     * consumed by admin2's badgeEndpoint mechanism.
     */
    public function updateBadge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $release = EasyformsUpdater::checkLatestRelease();

        return ApiResponse::create(['count' => $release['available'] ? 1 : 0]);
    }
}
