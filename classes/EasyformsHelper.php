<?php

declare(strict_types=1);

namespace Grav\Plugin\Easyform;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Common\File\CompiledYamlFile;
use Grav\Plugin\Form\Form;
use RocketTheme\Toolbox\File\JsonFile;

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

            // admin2's collapsed list-row label is the first non-empty string
            // value found in this array, in key order (confirmed by reading
            // the list field's row-label function in the compiled admin2
            // bundle) — there is no separate "label field" setting. `name`
            // (the technical slug) would otherwise win that race just by
            // being declared first in the blueprint, even though the form's
            // title is what a non-technical user actually recognizes it by.
            // Moving `title` to the front here, only when set, makes it win
            // instead, without touching the on-disk field order or the
            // blueprint.
            $title = trim((string) ($config['title'] ?? ''));
            if ($title !== '') {
                $config = ['title' => $title] + $config;
            }

            $config['shortcode'] = '[easyform name="' . $name . '"]';
            $summary = self::submissionsSummary($name);
            // A single JSON string rather than a nested object: this field
            // is declared as a plain text field (see easyform-admin2.yaml)
            // so admin2's generic list-row handling — proven to work for
            // ordinary string/number/bool values — applies to it too,
            // rather than relying on unverified behavior for a structured
            // value. The custom field script parses it back out.
            $config['submissions_summary'] = json_encode([
                'name' => $name,
                'total' => $summary['total'],
                'unread' => $summary['unread'],
            ], JSON_UNESCAPED_SLASHES);
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

            // Both are computed read-only display values injected by
            // listAllRaw() for the admin2 UI, not real form config — must
            // not round-trip into the stored file.
            unset($form['shortcode'], $form['submissions_summary']);
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
    /**
     * @param array<string,mixed>|null $postData The submitted `data[...]`
     *   values, when rebuilding this form to validate a submission (as
     *   opposed to a fresh GET render, where this is null). Used only to
     *   decide whether a conditionally-shown field's `required` constraint
     *   should apply: a field hidden by its trigger's current value must
     *   not block submission, but that has to be decided against the
     *   actual submitted trigger value, not just always dropped, or a
     *   visible-and-empty required field would stop validating anything.
     */
    public static function buildGravForm(string $name, array $config, ?array $postData = null): array
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
                // forms/default/field.html.twig runs `field.description|t`
                // unconditionally (unlike help/placeholder/title, which are
                // all guarded by an `if`), and the API/admin2 render path's
                // `t` filter (Grav\Plugin\Api\AdminProxy::translate(), typed
                // to always return string) throws on a null argument where
                // the normal frontend/classic-admin Language::translate()
                // tolerates one — only surfaced once the preview route
                // (EasyformsApiController::preview()) exercised this same
                // template through that code path. An explicit '' avoids it
                // everywhere without depending on which `t` is bound.
                'description' => '',
            ];

            if (!empty($fieldDef['placeholder'])) {
                $field['placeholder'] = $fieldDef['placeholder'];
            }

            $isRequired = Utils::isPositive($fieldDef['required'] ?? false);

            $showIfField = trim((string) ($fieldDef['show_if_field'] ?? ''));
            if ($showIfField !== '') {
                $showIfValue = (string) ($fieldDef['show_if_value'] ?? '');
                $field['datasets'] = [
                    'easyform-show-if-field' => $showIfField,
                    'easyform-show-if-value' => $showIfValue,
                ];

                // Only actually known once a submission is being validated
                // (see the docblock above) — a fresh GET render leaves
                // $isRequired as configured, since the field's initial
                // visibility is a client-side concern handled by JS, and
                // that same JS also drops the `required` attribute for
                // whatever starts out hidden.
                if ($isRequired && $postData !== null) {
                    $submittedTrigger = (string) ($postData[$showIfField] ?? '');
                    if ($submittedTrigger !== $showIfValue) {
                        $isRequired = false;
                    }
                }
            }

            if ($isRequired) {
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

        self::applyAntispam($name, $config, $fields, $process);

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

        if (Utils::isPositive($config['autoresponder_enabled'] ?? false)) {
            $recipientField = trim((string) ($config['autoresponder_field'] ?? '')) ?: 'email';
            $autoresponderParams = [
                'to' => '{{ form.value.' . $recipientField . ' }}',
            ];
            if (!empty($config['autoresponder_from'])) {
                $autoresponderParams['from'] = $config['autoresponder_from'];
            }
            if (!empty($config['autoresponder_subject'])) {
                $autoresponderParams['subject'] = $config['autoresponder_subject'];
            }
            if (!empty($config['autoresponder_message'])) {
                $autoresponderParams['body'] = $config['autoresponder_message'];
            }
            // A second, independent `email` process entry — the form
            // plugin dispatches every process action in order regardless
            // of prior ones of the same kind, so this fires alongside (not
            // instead of) the notification email above.
            $process[] = ['email' => $autoresponderParams];
        }

        if (Utils::isPositive($config['save_enabled'] ?? false)) {
            // Not the form plugin's own generic `save` action (which
            // writes a flat text/YAML dump): a `call` into our own storage
            // instead, so submissions come out as one structured JSON file
            // each, addressable by id — required for the admin submissions
            // list/detail views and CSV export to work at all.
            $process[] = ['call' => [self::class, 'saveSubmission']];
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

    public static function hasConditionalFields(array $config): bool
    {
        foreach ((array) ($config['fields'] ?? []) as $fieldDef) {
            if (is_array($fieldDef) && trim((string) ($fieldDef['show_if_field'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public static function antispamMethod(array $config): string
    {
        $method = (string) ($config['antispam_method'] ?? 'honeypot');

        return in_array($method, ['none', 'honeypot', 'math', 'turnstile', 'recaptcha'], true) ? $method : 'honeypot';
    }

    public static function honeypotFieldName(array $config): string
    {
        $name = trim((string) ($config['antispam_honeypot_field'] ?? ''));

        return $name !== '' ? $name : 'website_hp';
    }

    /**
     * Session key the math challenge is stored under for a given form. Kept
     * as its own named field name ('antispam_math') and this session key so
     * both the field-building side here and the validation hook in
     * easyform.php (onFormValidationProcessed) agree on where to look.
     */
    public static function mathSessionKey(string $formName): string
    {
        return 'easyform_math_' . $formName;
    }

    /**
     * Adds whichever spam-protection field/process-action the form is
     * configured for. Reuses the official `form` plugin's own mechanisms
     * wherever one exists:
     *
     * - honeypot: a `type: honeypot` field. The form plugin already throws
     *   a ValidationException on its own on submission if it's non-empty
     *   (FormPlugin::onFormValidationProcessed) — nothing else to add here.
     * - turnstile / recaptcha: a `type: captcha` field with the matching
     *   `provider`, plus a `captcha` process action so
     *   CaptchaManager::validateCaptcha() runs. Site/secret keys fall back
     *   from the per-form value to this plugin's own global setting; if
     *   neither is set, the key is simply omitted so the form plugin's own
     *   global plugins.form.{provider}.* config (if any) applies.
     * - math: no native equivalent, so this plugin implements it: a
     *   required text field asking a random a+b question, whose expected
     *   (hashed) answer is stored in the session and checked in
     *   easyform.php's own onFormValidationProcessed handler.
     *
     * The math/honeypot checks fire before the process chain even starts
     * (same lifecycle point form.php's own honeypot check uses), so a
     * failure there skips email/save entirely with no extra ordering care
     * needed. A captcha failure, however, is itself a process action — one
     * that stops Form::post()'s process loop when it fails — so it MUST be
     * the first entry `$process` receives, before message/email/save do;
     * that's why this runs before the rest of the process array is built.
     */
    private static function applyAntispam(string $name, array $config, array &$fields, array &$process): void
    {
        $method = self::antispamMethod($config);

        switch ($method) {
            case 'honeypot':
                $fields[] = [
                    'name' => self::honeypotFieldName($config),
                    'label' => '',
                    'type' => 'honeypot',
                    'description' => '',
                ];
                break;

            case 'math':
                $session = Grav::instance()['session'];
                $sessionKey = self::mathSessionKey($name);
                $challenge = $session->{$sessionKey} ?? null;

                if (!is_array($challenge) || !isset($challenge['a'], $challenge['b'], $challenge['hash'])) {
                    // Only generate a new question when none is pending —
                    // this method also runs while rebuilding the form object
                    // to validate a just-submitted answer, and regenerating
                    // it there would overwrite the expected answer before
                    // it's even checked.
                    $a = random_int(1, 10);
                    $b = random_int(1, 10);
                    $challenge = ['a' => $a, 'b' => $b, 'hash' => hash('sha256', (string) ($a + $b))];
                    $session->{$sessionKey} = $challenge;
                }

                $labelTemplate = trim((string) ($config['antispam_math_label'] ?? ''));
                if ($labelTemplate === '') {
                    $labelTemplate = 'Security question: what is {a} + {b}?';
                }
                $label = str_replace(['{a}', '{b}'], [(string) $challenge['a'], (string) $challenge['b']], $labelTemplate);

                $fields[] = [
                    'name' => 'antispam_math',
                    'label' => $label,
                    'type' => 'text',
                    'description' => '',
                    'validate' => ['required' => true],
                ];
                break;

            case 'turnstile':
            case 'recaptcha':
                $globalConfig = Grav::instance()['config'];

                $siteKey = trim((string) ($config['antispam_site_key'] ?? ''));
                if ($siteKey === '') {
                    $siteKey = trim((string) $globalConfig->get('plugins.easyform.antispam_' . $method . '_site_key', ''));
                }

                $secretKey = trim((string) ($config['antispam_secret_key'] ?? ''));
                if ($secretKey === '') {
                    $secretKey = trim((string) $globalConfig->get('plugins.easyform.antispam_' . $method . '_secret_key', ''));
                }

                $field = [
                    'name' => 'captcha',
                    'label' => '',
                    'type' => 'captcha',
                    'provider' => $method,
                    'description' => '',
                ];
                if ($siteKey !== '') {
                    $field[$method . '_site_key'] = $siteKey;
                }
                if ($method === 'recaptcha') {
                    // Both the field template and CaptchaManager read this
                    // per-field/per-param value ahead of the form plugin's
                    // own global config, so this alone is enough to force
                    // v3 regardless of that global setting.
                    $field['recaptcha_version'] = 3;
                }
                $fields[] = $field;

                $captchaParams = [];
                if ($secretKey !== '') {
                    $captchaParams[$method . '_secret'] = $secretKey;
                }
                if ($method === 'recaptcha') {
                    $captchaParams['recaptcha_version'] = 3;
                }
                $process[] = ['captcha' => $captchaParams];
                break;
        }
    }

    /*
     * -----------------------------------------------------------------
     * Submissions storage, listing, export
     * -----------------------------------------------------------------
     */

    public static function submissionsDir(string $formName): string
    {
        $locator = Grav::instance()['locator'];
        $dir = $locator->findResource('user-data://easyforms-submissions/' . $formName, true, true);

        if (!is_dir($dir)) {
            Folder::create($dir);
        }

        return $dir;
    }

    /**
     * Zeroes the host part of an IP address (the last octet for IPv4, the
     * last 80 bits for IPv6) rather than storing it in full — enough to
     * keep coarse geo/abuse signal without identifying an individual
     * visitor. Uses inet_pton/ntop and a bytewise mask rather than string
     * splitting, since IPv6's "::" zero-run compression makes that
     * unreliable.
     */
    public static function anonymizeIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }

        $mask = strlen($packed) === 4
            ? "\xFF\xFF\xFF\x00"
            : str_repeat("\xFF", 6) . str_repeat("\x00", 10);

        $anonymized = $packed & $mask;
        $result = inet_ntop($anonymized);

        return $result !== false ? $result : '';
    }

    /**
     * The `call` process action target for a form with `save_enabled` on
     * (see applyAntispam()'s sibling in buildGravForm() for where that's
     * wired up). Stores one JSON file per submission — structured, unlike
     * the form plugin's own generic `save` action, so the admin submissions
     * views and CSV export can actually parse individual entries.
     */
    public static function saveSubmission(Form $form): void
    {
        $name = $form->getName();
        $config = self::loadRaw($name);
        if ($config === null) {
            return;
        }

        $values = [];
        foreach ((array) ($config['fields'] ?? []) as $fieldDef) {
            if (!is_array($fieldDef) || empty($fieldDef['name'])) {
                continue;
            }
            $fieldName = (string) $fieldDef['name'];
            $values[$fieldName] = $form->value($fieldName);
        }

        $id = date('YmdHis') . '-' . substr(uniqid('', true), -8);
        $submission = [
            'id' => $id,
            'timestamp' => date('c'),
            'status' => 'unread',
            'ip' => self::anonymizeIp(Uri::ip()),
            'values' => $values,
        ];

        $path = self::submissionsDir($name) . '/' . $id . '.json';
        JsonFile::instance($path)->save($submission);

        $retentionDays = (int) ($config['storage_retention_days'] ?? 0);
        if ($retentionDays > 0) {
            self::pruneExpiredSubmissions($name, $retentionDays);
        }
    }

    /**
     * @return array<int,array<string,mixed>> Newest first.
     */
    public static function listSubmissions(string $formName): array
    {
        $dir = self::submissionsDir($formName);
        $list = [];

        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && isset($data['id'])) {
                $list[] = $data;
            }
        }

        usort($list, static fn(array $a, array $b): int => strcmp(
            (string) ($b['timestamp'] ?? ''),
            (string) ($a['timestamp'] ?? '')
        ));

        return $list;
    }

    public static function loadSubmission(string $formName, string $id): ?array
    {
        foreach (self::listSubmissions($formName) as $submission) {
            if (($submission['id'] ?? null) === $id) {
                return $submission;
            }
        }

        return null;
    }

    private static function findSubmissionFile(string $formName, string $id): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            return null;
        }

        $path = self::submissionsDir($formName) . '/' . $id . '.json';

        return is_file($path) ? $path : null;
    }

    public static function setSubmissionStatus(string $formName, string $id, string $status): bool
    {
        $path = self::findSubmissionFile($formName, $id);
        if ($path === null) {
            return false;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return false;
        }

        $data['status'] = $status;
        JsonFile::instance($path)->save($data);

        return true;
    }

    public static function deleteSubmission(string $formName, string $id): bool
    {
        $path = self::findSubmissionFile($formName, $id);

        return $path !== null && @unlink($path);
    }

    /**
     * @return array{total:int,unread:int}
     */
    public static function submissionsSummary(string $formName): array
    {
        $submissions = self::listSubmissions($formName);
        $unread = 0;
        foreach ($submissions as $submission) {
            if (($submission['status'] ?? 'unread') === 'unread') {
                $unread++;
            }
        }

        return ['total' => count($submissions), 'unread' => $unread];
    }

    /**
     * Deletes submissions older than $retentionDays, going by their stored
     * timestamp (falling back to the file's own mtime if that's ever
     * missing/unparsable). Called opportunistically on each new submission
     * rather than through a cron job, which this plugin has no dependency
     * on.
     */
    public static function pruneExpiredSubmissions(string $formName, int $retentionDays): void
    {
        if ($retentionDays <= 0) {
            return;
        }

        $cutoff = time() - ($retentionDays * 86400);
        $dir = self::submissionsDir($formName);

        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            $timestamp = is_array($data) ? strtotime((string) ($data['timestamp'] ?? '')) : false;
            $mtime = $timestamp !== false ? $timestamp : (int) filemtime($path);

            if ($mtime < $cutoff) {
                @unlink($path);
            }
        }
    }

    /**
     * UTF-8 CSV (with a BOM, and a semicolon delimiter for compatibility
     * with Excel under a German locale, where a plain comma is read as the
     * decimal separator instead of a field separator).
     */
    public static function submissionsCsv(string $formName): string
    {
        $config = self::loadRaw($formName) ?? [];
        $fieldNames = [];
        foreach ((array) ($config['fields'] ?? []) as $fieldDef) {
            if (is_array($fieldDef) && !empty($fieldDef['name'])) {
                $fieldNames[] = (string) $fieldDef['name'];
            }
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_merge(['Datum', 'Status', 'IP'], $fieldNames), ';');

        foreach (self::listSubmissions($formName) as $submission) {
            $row = [
                (string) ($submission['timestamp'] ?? ''),
                (string) ($submission['status'] ?? ''),
                (string) ($submission['ip'] ?? ''),
            ];
            foreach ($fieldNames as $fieldName) {
                $value = $submission['values'][$fieldName] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }
                $row[] = (string) $value;
            }
            fputcsv($handle, $row, ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Shows/hides a field's whole row (label + input, i.e. .form-field —
     * verified against the actual forms/default/field.html.twig markup) based
     * on another field's current value, and drops/restores `required` to
     * match, so a hidden field never blocks submission. Delegates on the
     * form's `change` event rather than binding one trigger element, since a
     * radio-button trigger is several inputs sharing one name and the first
     * match alone wouldn't see the others' changes.
     *
     * Mirrors the server-side required-stripping in buildGravForm() (keyed
     * off $postData), which is what actually enforces this on submission —
     * this script only keeps the visible UI honest and avoids pointless
     * required-but-hidden prompts.
     */
    private const CONDITIONAL_FIELDS_SCRIPT = <<<'JS'
<script>
(function () {
    var dependents = document.querySelectorAll('[data-easyform-show-if-field]');
    if (!dependents.length) {
        return;
    }

    function triggerValue(form, name) {
        var els = form.querySelectorAll('[name="data[' + name + ']"]');
        if (!els.length) {
            return '';
        }
        if (els.length === 1) {
            var el = els[0];
            return el.type === 'checkbox' ? (el.checked ? (el.value || '1') : '') : el.value;
        }
        for (var i = 0; i < els.length; i++) {
            if (els[i].checked) {
                return els[i].value;
            }
        }
        return '';
    }

    function sync(input) {
        var form = input.closest('form');
        var wrapper = input.closest('.form-field');
        if (!form || !wrapper) {
            return;
        }
        var name = input.getAttribute('data-easyform-show-if-field');
        var expected = input.getAttribute('data-easyform-show-if-value') || '';
        var visible = triggerValue(form, name) === expected;
        wrapper.style.display = visible ? '' : 'none';
        if (visible) {
            if (input.dataset.easyformRequired === '1') {
                input.setAttribute('required', 'required');
            }
        } else if (input.hasAttribute('required')) {
            input.dataset.easyformRequired = '1';
            input.removeAttribute('required');
        }
    }

    dependents.forEach(function (input) {
        sync(input);
        var form = input.closest('form');
        if (form) {
            form.addEventListener('change', function () { sync(input); });
        }
    });
})();
</script>
JS;

    /**
     * Renders a stored easyform for the current page, reusing the official
     * form plugin's own `forms/form.html.twig` template. Shared by the
     * frontend shortcode/Twig function (EasyformPlugin::renderForm(), which
     * just delegates here) and the admin preview routes below, which render
     * the exact same markup so a preview is never out of sync with the real
     * embed.
     *
     * If the `form` plugin's onPageInitialized() (see EasyformPlugin) already
     * built and processed this form as the request's active form — i.e. this
     * render follows a submission — that exact instance is reused so its
     * post-validation status/message make it into the output. Building a
     * fresh Form here instead would silently discard the just-processed
     * result and render what looks like an untouched, never-submitted form.
     */
    public static function renderFormHtml(?string $name): string
    {
        if (!$name) {
            return '';
        }

        $grav = Grav::instance();

        /** @var \Grav\Common\Page\Interfaces\PageInterface|null $page */
        $page = $grav['page'] ?? null;
        if (!$page) {
            return '';
        }

        // A normal frontend/admin-classic page render always has Twig
        // initialized by this point; an API request (the admin2 preview
        // route) never triggers that on its own. init() is a no-op once
        // already initialized, so this is safe either way.
        $grav['twig']->init();

        $forms = $grav['forms'];
        $active = $forms->getActiveForm();

        if ($active instanceof Form && $active->getName() === $name) {
            $form = $active;
        } else {
            $config = self::loadRaw($name);
            if (!$config) {
                return '';
            }

            $formArray = self::buildGravForm($name, $config);
            $form = $forms->createPageForm($page, $name, $formArray);
            if (!$form) {
                return '';
            }
        }

        // A rendered form embeds a one-time nonce; never let the page cache
        // serve a stale one to the next visitor. modifyHeader() writes
        // straight to the page's internal header object with no lazy-load —
        // harmless for a normal frontend page, whose header is already
        // loaded by the time content is rendered, but the site root Page the
        // admin preview route hands in (see EasyformsApiController::preview())
        // has no backing content file, so even header()'s own lazy-load (which
        // reads the file when the header is still unset) leaves it null.
        // Forcing a value through the header($var) setter guarantees an
        // object exists either way.
        if (!$page->header()) {
            $page->header(['title' => '']);
        }
        $page->modifyHeader('never_cache_twig', true);

        // The form template (third-party `form` plugin) runs `field.description|t`
        // unconditionally on every field, including hidden bookkeeping fields
        // (nonce, unique-id) the form plugin injects itself — none of which
        // set a description, so it's null there regardless of anything this
        // plugin builds. Core's `t` filter (GravExtension::translate())
        // silently tolerates a null lookup via the normal Language::translate()
        // path, but routes through Grav\Plugin\Api\AdminProxy::translate()
        // instead whenever $grav['admin'] is set — which the API plugin does
        // for the whole request, not just admin-authored templates — and that
        // proxy is typed to always return string, so it fatals on the same
        // null. Unsetting it only around this one render call restores the
        // safe path without affecting anything else the request still needs
        // $grav['admin'] for.
        $hadAdmin = isset($grav['admin']);
        $adminValue = $hadAdmin ? $grav['admin'] : null;
        if ($hadAdmin) {
            unset($grav['admin']);
        }
        try {
            $html = $grav['twig']->processTemplate('forms/form.html.twig', ['form' => $form]);
        } finally {
            if ($hadAdmin) {
                $grav['admin'] = $adminValue;
            }
        }

        $config = self::loadRaw($name);
        if ($config && self::antispamMethod($config) === 'honeypot') {
            // The form plugin's own honeypot field hides itself with
            // visibility:hidden, which some bots specifically look for and
            // skip filling in (defeating the trap). Positioning it off-screen
            // instead, without display:none, is a more effective disguise —
            // overridden here rather than in the honeypot field's own
            // template, which belongs to the (third-party) form plugin.
            $html = '<style>.form-honeybear{opacity:0!important;position:absolute!important;'
                . 'top:0!important;left:-9999px!important;height:0!important;width:0!important;'
                . 'z-index:-1!important;}</style>' . $html;
        }

        if ($config && self::hasConditionalFields($config)) {
            $html .= self::CONDITIONAL_FIELDS_SCRIPT;
        }

        return $html;
    }

    /**
     * Standalone preview page for a stored form: the exact same markup
     * renderFormHtml() produces for the real embed, wrapped in a plain HTML
     * shell with a banner, opened in a new tab from the admin. Deliberately
     * shows only the last *saved* state — it reads straight off disk via
     * loadRaw(), the same as the live embed does — with the banner telling
     * an editor to save first if they don't see their latest changes, and
     * disables every field/button via a wrapping <fieldset disabled> so the
     * preview can never be used to actually submit the form (nothing here
     * runs the real validate/email/save pipeline; a fieldset is the simplest
     * way to guarantee that without a second, unvalidated code path).
     */
    public static function renderPreviewHtml(string $name): string
    {
        $grav = Grav::instance();
        $language = $grav['language'];

        if (!self::exists($name)) {
            return self::previewShell(
                $name,
                '<p>' . htmlspecialchars($language->translate('PLUGIN_EASYFORMS.PREVIEW_NOT_SAVED'), ENT_QUOTES) . '</p>'
            );
        }

        $formHtml = self::renderFormHtml($name);
        if ($formHtml === '') {
            return self::previewShell(
                $name,
                '<p>' . htmlspecialchars($language->translate('PLUGIN_EASYFORMS.PREVIEW_ERROR'), ENT_QUOTES) . '</p>'
            );
        }

        return self::previewShell($name, '<fieldset disabled style="border:0;margin:0;padding:0;">' . $formHtml . '</fieldset>');
    }

    private static function previewShell(string $name, string $bodyHtml): string
    {
        $grav = Grav::instance();
        $language = $grav['language'];

        $title = htmlspecialchars(
            $language->translate('PLUGIN_EASYFORMS.PREVIEW') . ': ' . $name,
            ENT_QUOTES
        );
        $banner = htmlspecialchars($language->translate('PLUGIN_EASYFORMS.PREVIEW_BANNER'), ENT_QUOTES);
        $lang = htmlspecialchars((string) $language->getActive() ?: 'en', ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>{$title}</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;color:#222;}
.easyform-preview-banner{background:#fff3cd;border:1px solid #ffe69c;color:#664d03;padding:.75rem 1rem;border-radius:.375rem;margin-bottom:1.5rem;font-size:.9rem;}
fieldset{opacity:.85;}
button,input,select,textarea{cursor:not-allowed !important;}
</style>
</head>
<body>
<div class="easyform-preview-banner">{$banner}</div>
{$bodyHtml}
</body>
</html>
HTML;
    }
}
