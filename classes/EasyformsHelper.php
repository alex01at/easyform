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
