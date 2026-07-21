<?php

namespace Modules\Shipments\Services;

use Illuminate\Support\Str;
use Modules\Shipments\Models\CourierAccount;
use RuntimeException;

class InPostCourierParcelTemplateService
{
    public function all(CourierAccount $account): array
    {
        return collect($account->setting('parcel_templates', []))
            ->filter(fn (mixed $template): bool => is_array($template))
            ->map(fn (array $template): array => $this->normalize($template))
            ->filter(fn (array $template): bool => $template['id'] !== '' && $template['name'] !== '')
            ->values()
            ->all();
    }

    public function create(CourierAccount $account, array $data): array
    {
        $template = $this->normalize($data + ['id' => (string) Str::uuid()]);
        $templates = $this->all($account);
        $templates[] = $template;
        $this->save($account, $templates);

        return $template;
    }

    public function update(CourierAccount $account, string $templateId, array $data): array
    {
        $templates = $this->all($account);
        $index = collect($templates)->search(fn (array $template): bool => $template['id'] === $templateId);

        if ($index === false) {
            throw new RuntimeException('Nie znaleziono szablonu przesylki.');
        }

        $templates[$index] = $this->normalize($data + ['id' => $templateId]);
        $this->save($account, $templates);

        return $templates[$index];
    }

    public function delete(CourierAccount $account, string $templateId): void
    {
        $templates = collect($this->all($account))
            ->reject(fn (array $template): bool => $template['id'] === $templateId)
            ->values()
            ->all();

        $this->save($account, $templates);
    }

    private function save(CourierAccount $account, array $templates): void
    {
        $settings = $account->settings ?? [];
        $settings['parcel_templates'] = array_values($templates);
        $account->settings = $settings;
        $account->save();
    }

    private function normalize(array $template): array
    {
        return [
            'id' => trim((string) ($template['id'] ?? '')),
            'name' => trim((string) ($template['name'] ?? '')),
            'weight' => (float) ($template['weight'] ?? 0),
            'length' => (float) ($template['length'] ?? 0),
            'width' => (float) ($template['width'] ?? 0),
            'height' => (float) ($template['height'] ?? 0),
        ];
    }
}
