<?php

declare(strict_types=1);

namespace Supertext\NeosTranslation\Service;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\Flow\Annotations as Flow;
use Supertext\NeosTranslation\Configuration\Settings;

/**
 * Decides which properties of a node type are translated, derived from the node
 * type definition:
 *
 *  - string properties only;
 *  - inline-editable properties are translated as HTML;
 *  - Inspector properties with a text editor (TextField, TextArea) as plain text,
 *    with the RichTextEditor as HTML; a string property in the Inspector without an
 *    explicit editor gets Neos' default TextFieldEditor and counts as plain text;
 *  - properties.<name>.options.supertext.translate: true|false overrides all of it
 *    (true translates as plain text unless the property is inline-editable or rich text).
 */
#[Flow\Scope('singleton')]
class PropertySelector
{
    #[Flow\Inject]
    protected Settings $settings;

    /** @var array<string, array<string, bool>> */
    private array $cache = [];

    /** @return array<string, bool> property name => translate as HTML */
    public function translatableProperties(NodeType $nodeType): array
    {
        $key = $nodeType->name->value;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $result = [];
        $excluded = $this->settings->getExcludedProperties();
        foreach ($nodeType->getProperties() as $name => $config) {
            $name = (string)$name;
            if (!is_array($config) || str_starts_with($name, '_') || ($config['type'] ?? 'string') !== 'string') {
                continue;
            }
            $override = $config['options']['supertext']['translate'] ?? null;
            if ($override === false || ($override === null && in_array($name, $excluded, true))) {
                continue;
            }

            $inline = (bool)($config['ui']['inlineEditable'] ?? false);
            $inspector = $config['ui']['inspector'] ?? null;
            $editor = is_array($inspector) ? (string)($inspector['editor'] ?? 'Neos.Neos/Inspector/Editors/TextFieldEditor') : '';
            $isRichText = $editor !== '' && in_array($editor, $this->settings->getRichTextEditors(), true);
            $isPlainText = $editor !== '' && in_array($editor, $this->settings->getPlainTextEditors(), true);

            if ($inline || $isRichText) {
                $result[$name] = true;
            } elseif ($isPlainText || $override === true) {
                $result[$name] = false;
            }
        }
        return $this->cache[$key] = $result;
    }
}
