<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Data;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Common\Twig\Twig;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Api\PermissionResolver;
use Grav\Plugin\Easyform\EasyformsApiController;
use Grav\Plugin\Easyform\EasyformsHelper;
use Grav\Plugin\Easyform\EasyformsUpdater;
use Grav\Plugin\Form\Form;
use RocketTheme\Toolbox\Event\Event;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;
use Twig\TwigFunction;

/**
 * Easy Forms: manages Grav forms as standalone entities editable from the
 * Admin panel and embeddable anywhere via [easyform name="..."] or
 * {{ easyform('...') }}.
 *
 * This plugin does not reimplement form processing. It only stores form
 * definitions and translates them into the array shape the official `form`
 * plugin's Form class expects; validation, email, save and redirect actions
 * are all executed by that plugin.
 */
class EasyformPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 0],
            // admin2 (API-driven admin) integration. Unconditional, like the
            // admin-classic hooks below are gated by isAdmin(): these events
            // are only ever fired by the `api` plugin, so they're harmless
            // no-ops on a site that doesn't have it installed.
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->setPsr4('Grav\\Plugin\\Easyform\\', __DIR__ . '/classes');
        $loader->register();

        return $loader;
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml('plugin://' . $this->name . '/permissions.yaml');
        $event->permissions->addActions($actions);
    }

    /*
     * -----------------------------------------------------------------
     * admin2 (API-driven admin) integration
     *
     * admin2's plugin-page route is a single URL segment ("/plugin/[slug]"),
     * and the endpoint that resolves the page's blueprint
     * (BlueprintController::pluginPageBlueprint()) does a raw filesystem
     * lookup treating that segment as a real, installed plugin folder name —
     * there is no event hook to override that, so a separate synthetic page
     * per form (mirroring admin-classic's list+add+edit routes) is not
     * possible here; the segment MUST be "easyform", the plugin's own real
     * slug. So every form is managed as one row of a single repeatable list
     * field on the one page at /plugin/easyform, and the GitHub-update
     * status/action lives on that same page rather than a separate one, for
     * the same reason.
     * -----------------------------------------------------------------
     */

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = EasyformsApiController::class;

        $routes->get('/easyform', [$controller, 'show']);
        $routes->patch('/easyform', [$controller, 'save']);
        $routes->get('/easyform/_badge', [$controller, 'badge']);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $user = $event['user'];
        if (!$this->userCanManageForms($user)) {
            return;
        }

        $items = $event['items'] ?? [];

        $items[] = [
            'id' => 'easyform',
            'plugin' => 'easyform',
            'label' => 'PLUGIN_EASYFORMS.MENU',
            'icon' => 'fa-wpforms',
            'route' => '/plugin/easyform',
            'priority' => 10,
            'badgeEndpoint' => '/easyform/_badge',
            'authorize' => ['admin.easyform', 'admin.super', 'api.easyform', 'api.super'],
        ];

        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if ((string) $event['plugin'] !== 'easyform') {
            return;
        }

        $user = $event['user'];
        if (!$this->userCanManageForms($user)) {
            return;
        }

        $event['definition'] = [
            'id' => 'easyform',
            'plugin' => 'easyform',
            'title' => $this->grav['language']->translate('PLUGIN_EASYFORMS.MENU'),
            'icon' => 'wpforms',
            'page_type' => 'blueprint',
            'blueprint' => 'easyform-admin2',
            'data_endpoint' => '/easyform',
            'save_endpoint' => '/easyform',
            'actions' => [
                [
                    'id' => 'save',
                    'label' => $this->grav['language']->translate('PLUGIN_EASYFORMS.SAVE'),
                    'icon' => 'fa-check',
                    'primary' => true,
                ],
            ],
        ];
    }

    /**
     * Checks the admin.easyform / admin.super permission for either kind of
     * authenticated caller this plugin can see:
     *
     * - admin-classic: User::authorize() works, but takes a single string
     *   (not an array like Admin::authorize()'s wrapper), so both permissions
     *   must be checked separately — admin.super does not imply an arbitrary
     *   admin.* action.
     * - admin2/API: the user was authenticated via JWT and never got the
     *   legacy 'authenticated' flag User::authorize() requires, so it always
     *   returns false for these users regardless of their actual access —
     *   the API's own PermissionResolver (used internally by every API
     *   controller) has to be used instead.
     *
     * Grav 2.0 also keeps admin-classic and admin2/API super-admin authority
     * as two deliberately separate flags: an account created purely through
     * admin2 (or the CLI's `--admin-type api`) carries only `access.api.super`
     * and has no `access.admin.*` at all, while one created through
     * admin-classic carries `access.admin.super` and typically no
     * `access.api.*`. A single-namespace check leaves the other kind of
     * account with no way to see this plugin at all, so every combination is
     * checked.
     */
    private function userCanManageForms($user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->authorize('admin.super') || $user->authorize('admin.easyform')) {
            return true;
        }

        $resolver = new PermissionResolver($this->grav['permissions'] ?? null);

        return (bool) (
            $resolver->resolve($user, 'admin.super')
            || $resolver->resolve($user, 'admin.easyform')
            || $resolver->resolve($user, 'api.super')
            || $resolver->resolve($user, 'api.easyform')
        );
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminMenu' => ['onAdminMenu', 0],
                'onAdminData' => ['onAdminData', 0],
                'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
                'onTwigSiteVariables' => ['onTwigAdminVariables', 0],
            ]);

            return;
        }

        $this->enable([
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
            // Higher priority than the `form` plugin's own onPageInitialized (0),
            // so our form is registered as the active one before it looks for it.
            'onPageInitialized' => ['onPageInitialized', 10],
        ]);
    }

    /*
     * -----------------------------------------------------------------
     * Admin panel integration
     * -----------------------------------------------------------------
     */

    public function onAdminMenu(): void
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        $twig->plugins_hooked_nav['PLUGIN_EASYFORMS.MENU'] = [
            'route' => 'easyform',
            'icon' => 'fa-wpforms',
            'authorize' => ['admin.easyform', 'admin.super', 'api.easyform', 'api.super'],
            'priority' => 90,
        ];
    }

    /**
     * Injects the list of stored forms as a twig variable when we're on our
     * own admin page. The add/edit form itself is fetched by the template
     * directly via admin.data('easyform/...'), same as core config pages do.
     */
    public function onTwigAdminVariables(): void
    {
        $admin = $this->grav['admin'] ?? null;
        if (!$admin || $admin->location !== 'easyform') {
            return;
        }

        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $twig->twig_vars['easyforms_list'] = EasyformsHelper::listForms();

        if (EasyformsUpdater::getRepo() !== '') {
            $twig->twig_vars['easyforms_update'] = EasyformsUpdater::checkLatestRelease();
        }
    }

    /**
     * Provides the Data object behind admin.data('easyform/{name}'), backed
     * by our own blueprint and by user/data/easyforms/{name}.yaml instead of
     * the usual config/ or plugins/ locations.
     */
    public function onAdminData(Event $event): void
    {
        $type = (string) $event['type'];
        if (!str_starts_with($type, 'easyform/')) {
            return;
        }

        $name = substr($type, strlen('easyform/'));

        $blueprint = new Blueprint('plugin://' . $this->name . '/blueprints/easyform.yaml');
        $blueprint->load();

        $existing = $name !== '' ? (EasyformsHelper::loadRaw($name) ?? ['name' => $name]) : [];

        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $post = (array) ($uri->post()['data'] ?? []);

        $data = new Data($post ?: $existing, $blueprint);

        $event['data_type'] = $data;
    }

    /**
     * Handles the save/delete tasks for our admin view. The core
     * AdminController doesn't know about the "easyform" view, so it fires
     * onAdminTaskExecute for any task it can't resolve itself; that's our
     * hook for taskEasyformSave/taskEasyformDelete.
     */
    public function onAdminTaskExecute(Event $event): void
    {
        $controller = $event['controller'];
        if (!isset($controller->view) || $controller->view !== 'easyform') {
            return;
        }

        $method = (string) $event['method'];

        if ($method === 'taskEasyformSave') {
            $this->taskEasyformSave($controller);
            $event->stopPropagation();
        } elseif ($method === 'taskEasyformDelete') {
            $this->taskEasyformDelete($controller);
            $event->stopPropagation();
        } elseif ($method === 'taskEasyformUpdate') {
            $this->taskEasyformUpdate($controller);
            $event->stopPropagation();
        }
    }

    protected function taskEasyformSave($controller): void
    {
        if (!$controller->authorizeTask('save', ['admin.easyform', 'admin.super', 'api.easyform', 'api.super'])) {
            return;
        }

        $route = trim((string) $controller->route, '/');
        $existingName = Utils::startsWith($route, 'edit/') ? substr($route, 5) : null;

        $post = (array) $controller->post;
        $data = (array) ($post['data'] ?? []);

        $name = trim((string) ($data['name'] ?? $existingName ?? ''));

        // The name is the filename; once a form exists it cannot be renamed
        // through this form (the field is only meaningful when creating).
        if ($existingName !== null) {
            $name = $existingName;
        }

        if (!EasyformsHelper::isValidName($name)) {
            $controller->setMessage($this->grav['language']->translate('PLUGIN_EASYFORMS.ERROR_INVALID_NAME'), 'error');
            $controller->setRedirect('/easyform/' . ($existingName ? 'edit/' . $existingName : 'add'));

            return;
        }

        if ($existingName === null && EasyformsHelper::exists($name)) {
            $controller->setMessage($this->grav['language']->translate('PLUGIN_EASYFORMS.ERROR_NAME_TAKEN'), 'error');
            $controller->setRedirect('/easyform/add');

            return;
        }

        $data['name'] = $name;

        EasyformsHelper::save($name, $data);

        $controller->setMessage($this->grav['language']->translate('PLUGIN_EASYFORMS.SAVED'), 'info');
        $controller->setRedirect('/easyform');
    }

    protected function taskEasyformDelete($controller): void
    {
        if (!$controller->authorizeTask('delete', ['admin.easyform', 'admin.super', 'api.easyform', 'api.super'])) {
            return;
        }

        $route = trim((string) $controller->route, '/');
        $name = Utils::startsWith($route, 'edit/') ? substr($route, 5) : '';

        if ($name !== '' && EasyformsHelper::exists($name)) {
            EasyformsHelper::delete($name);
            $controller->setMessage($this->grav['language']->translate('PLUGIN_EASYFORMS.DELETED'), 'info');
        }

        $controller->setRedirect('/easyform');
    }

    /**
     * Downloads and installs the latest GitHub release in place. Restricted
     * to super admins, unlike the regular save/delete tasks: this replaces
     * the plugin's own code on disk.
     */
    protected function taskEasyformUpdate($controller): void
    {
        if (!$controller->authorizeTask('update', ['admin.super'])) {
            return;
        }

        $result = EasyformsUpdater::applyUpdate();
        $controller->setMessage($result['message'], $result['success'] ? 'info' : 'error');
        $controller->setRedirect('/easyform');
    }

    /*
     * -----------------------------------------------------------------
     * Frontend: shortcode, Twig function, form submission
     * -----------------------------------------------------------------
     */

    public function onShortcodeHandlers(): void
    {
        $this->grav['shortcode']->getHandlers()->add('easyform', function (ShortcodeInterface $sc) {
            return $this->renderForm($sc->getParameter('name'));
        });
    }

    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig()->addFunction(
            new TwigFunction('easyform', [$this, 'renderForm'], ['is_safe' => ['html']])
        );
    }

    /**
     * Renders a stored easyform for the current page, reusing the official
     * form plugin's own `forms/form.html.twig` template.
     *
     * If onPageInitialized() (below) already built and processed this form
     * as the request's active form — i.e. this render follows a submission —
     * that exact instance is reused so its post-validation status/message
     * make it into the output. Building a fresh Form here instead, as this
     * method used to, would silently discard the just-processed result and
     * render what looks like an untouched, never-submitted form.
     */
    public function renderForm(?string $name): string
    {
        if (!$name) {
            return '';
        }

        /** @var PageInterface|null $page */
        $page = $this->grav['page'] ?? null;
        if (!$page) {
            return '';
        }

        $forms = $this->grav['forms'];
        $active = $forms->getActiveForm();

        if ($active instanceof Form && $active->getName() === $name) {
            $form = $active;
        } else {
            $config = EasyformsHelper::loadRaw($name);
            if (!$config) {
                return '';
            }

            $formArray = EasyformsHelper::buildGravForm($name, $config);
            $form = $forms->createPageForm($page, $name, $formArray);
            if (!$form) {
                return '';
            }
        }

        // A rendered form embeds a one-time nonce; never let the page cache
        // serve a stale one to the next visitor.
        $page->modifyHeader('never_cache_twig', true);

        return $this->grav['twig']->processTemplate('forms/form.html.twig', ['form' => $form]);
    }

    /**
     * Catches a submitted easyform before the `form` plugin looks for the
     * active form, so that its own onPageInitialized processes it exactly
     * like it would a page-defined form (validation, email, save, redirect).
     */
    public function onPageInitialized(): void
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        if (!$uri->post('form-nonce')) {
            return;
        }

        $name = (string) ($uri->post('__form-name__') ?? '');
        if ($name === '' || !EasyformsHelper::exists($name)) {
            return;
        }

        $forms = $this->grav['forms'];
        if ($forms->getActiveForm() !== null) {
            return;
        }

        /** @var PageInterface|null $page */
        $page = $this->grav['page'] ?? null;
        if (!$page) {
            return;
        }

        $config = EasyformsHelper::loadRaw($name);
        if (!$config) {
            return;
        }

        $formArray = EasyformsHelper::buildGravForm($name, $config);
        $form = $forms->createPageForm($page, $name, $formArray);
        if ($form instanceof Form) {
            $forms->setActiveForm($form);
            $page->modifyHeader('never_cache_twig', true);
        }
    }
}
