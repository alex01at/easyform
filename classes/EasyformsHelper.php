<?php

declare(strict_types=1);

namespace Grav\Plugin\Easyform;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\File\CompiledYamlFile;

/**
 * Reads, writes and translates easyform definitions.
 *
 * Storage format (user/data/easyforms/{name}.yaml) is a small, admin-friendly
 * schema. buildGravForm() bridges it into the array shape the official
 * `form` plugin expects (`fields` + `process`), so all validation, email
 * sending, saving and redirecting is handled entirely by that plugin.
 */
class EasyformsHelper
{
    private const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public static function isValidName(string $name): bool
    {
        return $name !== '' && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Directory the easyform YAML files live in. Creates it on first use.
     */
    public static function dataDir(): string
    {
        $locator = Grav::instance()['locator'];
        $dir = $locator->findResource('user-data://easyforms', true, true);

        if (!is_dir($dir)) {
            Folder::create($dir);
        }

        return $dir;
    }

    public static function formFilePath(string $name): string
    {
        return self::dataDir() . '/' . $name . '.yaml';
    }

    public static function exists(string $name): bool
    {
        return self::isValidName($name) && is_file(self::formFilePath($name));
    }

    /**
     * Forms created with an earlier version of this plugin (0.1.2 and
     * before) could end up with these two exact strings literally stored as
     * their `to`/`from` value: the blueprint used a `default:` there to
     * document the site-wide fallback, but Grav shows/stores a blueprint
     * default verbatim rather than evaluating it, and admin2's "new row"
     * handler copies every field's default into the row's data as soon as
     * it's created. The value still worked correctly for sending mail (the
     * email plugin evaluates it as Twig at submission time regardless of
     * where it came from), but looked broken wherever it was displayed
     * un-evaluated — including becoming an existing row's list summary,
     * since that picks the first non-empty string field by insertion order,
     * and these were inserted before the user had typed anything else.
     * Silently treated as "not set" wherever a form is loaded, so already-
     * created forms heal themselves without the user having to re-save.
     */
    private const STALE_EMAIL_DEFAULTS = [
        'to' => '{{ config.plugins.email.to }}',
        'from' => '{{ config.plugins.email.from }}',
    ];

    /**
     * @return array<string,mixed>|null
     */
    public static function loadRaw(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }

        $file = CompiledYamlFile::instance(self::formFilePath($name));
        if (!$file->exists()) {
            return null;
        }

        $content = (array) $file->content();
        $file->free();

        foreach (self::STALE_EMAIL_DEFAULTS as $key => $staleValue) {
            if (($content[$key] ?? null) === $staleValue) {
                $content[$key] = '';
            }
        }

        return $content;
    }

    public static function save(string $name, array $data): void
    {
        $file = CompiledYamlFile::instance(self::formFilePath($name));
        $file->save($data);
        $file->free();
    }

    public static function delete(string $name): bool
    {
        if (!self::exists($name)) {
            return false;
        }

        $file = CompiledYamlFile::instance(self::formFilePath($name));
        $result = $file->delete();

        $submissions = Grav::instance()['locator']->findResource(
            'user-data://easyforms-submissions/' . $name,
            true,
            false
        );
        if ($submissions && is_dir($submissions)) {
            Folder::delete($submissions);
        }

        return $result;
    }

    /**
     * List all stored easyform as [name => ['name' => .., 'title' => .., 'shortcode' => ..]].
     *
     * @return array<string,array<string,string>>
     */
    public static function listForms(): array
    {
        $dir = self::dataDir();
        $list = [];

        foreach (glob($dir . '/*.yaml') ?: [] as $path) {
            $name = basename($path, '.yaml');
            if (!self::isValidName($name)) {
                continue;
            }

            $config = self::loadRaw($name) ?? [];
            $list[$name] = [
                'name' => $name,
                'title' => (string) ($config['title'] ?? $name),
                'shortcode' => '[easyform name="' . $name . '"]',
            ];
        }

        ksort($list);

        return $list;
    }

    /**
     * Full raw configs for every stored form, each annotated with its
     * shortcode for display. Used by the admin2 single-page list editor,
     * which manages every form as one repeatable list field rather than a
     * separate page per form (admin2's plugin-page route only supports one
     * URL segment, and it must be a real installed plugin's slug — a
     * separate synthetic page per form, as admin-classic uses, is not
     * possible there).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listAllRaw(): array
    {
        $list = [];

        foreach (array_keys(self::listForms()) as $name) {
            $config = self::loadRaw($name) ?? ['name' => $name];
            $config['shortcode'] = '[easyform name="' . $name . '"]';
            $list[] = $config;
        }

        return $list;
    }

    /**
     * Replaces the whole set of stored forms with $forms, as submitted by
     * the admin2 list editor: existing forms are overwritten, new ones
     * created, and any name no longer present in the list is deleted. This
     * also handles a rename correctly — the old name simply disappears from
     * the submitted list and its file is removed, while the new name is
     * written as if newly created.
     *
     * @param array<int,array<string,mixed>> $forms
     * @return array{saved:list<string>,errors:list<string>}
     */
    public static function syncFromList(array $forms): array
    {
        $existingNames = array_keys(self::listForms());
        $submittedNames = [];
        $errors = [];

        foreach ($forms as $form) {
            if (!is_array($form)) {
                continue;
            }

            $name = trim((string) ($form['name'] ?? ''));

            if (!self::isValidName($name)) {
                $errors[] = "Invalid form name: '{$name}'.";
                continue;
            }

            if (in_array($name, $submittedNames, true)) {
                $errors[] = "Duplicate form name: '{$name}'.";
                continue;
            }

            unset($form['shortcode']);
            $form['name'] = $name;
            self::save($name, $form);
            $submittedNames[] = $name;
        }

        foreach (array_diff($existingNames, $submittedNames) as $removedName) {
            self::delete($removedName);
        }

        return ['saved' => $submittedNames, 'errors' => $errors];
    }

    /**
     * Translate the admin-friendly easyform config into the array shape the
     * official `form` plugin's Form class expects: ['name' => ..,
     * 'fields' => [...], 'process' => [...], 'buttons' => [...]].
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function buildGravForm(string $name, array $config): array
    {
        $fields = [];

        foreach ((array) ($config['fields'] ?? []) as $fieldDef) {
            if (!is_array($fieldDef) || empty($fieldDef['name'])) {
                continue;
            }

            $fieldName = (string) $fieldDef['name'];
            $type = (string) ($fieldDef['type'] ?? 'text');

            $field = [
                'name' => $fieldName,
                'label' => (string) ($fieldDef['label'] ?? ucfirst($fieldName)),
                'type' => $type,
            ];

            if (!empty($fieldDef['placeholder'])) {
                $field['placeholder'] = $fieldDef['placeholder'];
            }

            if (Utils::isPositive($fieldDef['required'] ?? false)) {
                $field['validate'] = ['required' => true];
            }

            if ($type === 'select') {
                $options = [];
                foreach ((array) ($fieldDef['options'] ?? []) as $option) {
                    if (is_array($option) && isset($option['key']) && $option['key'] !== '') {
                        $options[(string) $option['key']] = (string) ($option['value'] ?? $option['key']);
                    }
                }
                if ($options) {
                    $field['options'] = $options;
                }
            }

            if ($type === 'file') {
                $field['destination'] = 'user-data://easyforms-submissions/' . $name . '/files';
                $field['accept'] = ['*'];
            }

            $fields[] = $field;
        }

        $process = [];

        if (!empty($config['message'])) {
            $process[] = ['message' => $config['message']];
        }

        if (Utils::isPositive($config['email_enabled'] ?? false)) {
            $emailParams = [];
            foreach (['to', 'from', 'reply_to', 'subject'] as $key) {
                if (!empty($config[$key])) {
                    $emailParams[$key] = $config[$key];
                }
            }
            $process[] = ['email' => $emailParams];
        }

        if (Utils::isPositive($config['save_enabled'] ?? false)) {
            $process[] = ['save' => [
                'folder' => 'easyforms-submissions/' . $name,
                'fileprefix' => $name . '-',
            ]];
        }

        $hasRedirect = !empty($config['redirect']);

        if (!$hasRedirect && Utils::isPositive($config['reset'] ?? true)) {
            $process[] = ['reset' => true];
        }

        if ($hasRedirect) {
            $process[] = ['redirect' => $config['redirect']];
        }

        return [
            'name' => $name,
            'fields' => $fields,
            'buttons' => [
                ['type' => 'submit', 'value' => 'PLUGIN_EASYFORMS.SUBMIT'],
            ],
            'process' => $process,
        ];
    }
}
