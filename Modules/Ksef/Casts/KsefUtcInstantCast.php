<?php

namespace Modules\Ksef\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use UnexpectedValueException;

final class KsefUtcInstantCast implements CastsAttributes
{
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?CarbonImmutable {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            $this->invalid($key);
        }

        $format = $model->getDateFormat();

        try {
            $instant = CarbonImmutable::createFromFormat('!'.$format, $value, 'UTC');
        } catch (Throwable) {
            $this->invalid($key);
        }

        if ($instant === false || $instant->format($format) !== $value) {
            $this->invalid($key);
        }

        return $instant;
    }

    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface) {
            $this->invalid($key);
        }

        return CarbonImmutable::instance($value)
            ->utc()
            ->format($model->getDateFormat());
    }

    private function invalid(string $key): never
    {
        throw new UnexpectedValueException(
            "Nieprawidłowy zapis czasu UTC Latarni KSeF w polu {$key}.",
        );
    }
}
