<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\MethodParameterClosureTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticMethodParameterClosureTypeExtension;
use PHPStan\Type\Type;

/**
 * Typ zapytania w domknięciu `whereHas('autor', fn ($q) => …)` (issue #1731).
 *
 * Larastan 3 zna typ domknięcia tylko wtedy, gdy relacja jest podana jako
 * OBIEKT (`Relation<TRelatedModel, …>`). U nas — jak w całym Laravelu —
 * relacja jest napisem, więc domknięcie dostawało `Builder<Model>` i każdy
 * nasz zakres (`->dostepnyJakoAutor()`, `->widocznyJakoOsoba()`) wyglądał
 * dla analizy na metodę, której nie ma. Na poziomie 2 to było kilkadziesiąt
 * fałszywych błędów w miejscach, które pilnują WIDOCZNOŚCI treści — czyli
 * dokładnie tam, gdzie literówka w nazwie zakresu byłaby najdroższa.
 *
 * To rozszerzenie niczego nie wycisza. Idzie ścieżką relacji (także
 * z kropkami: `'recipe.author'`) po metodach modeli i czyta typ docelowy
 * z ich `@return BelongsTo<User, $this>`. Gdy czegokolwiek nie da się
 * ustalić — relacja nie jest stałym napisem, metody nie ma, zwraca coś
 * bez generyków — oddaje `null`, a PHPStan zostaje przy swoim typie.
 * Nie zgaduje więc na korzyść kodu: nieznana relacja dalej daje błąd.
 */
final class DomkniecieRelacjiExtension implements MethodParameterClosureTypeExtension, StaticMethodParameterClosureTypeExtension
{
    /** Metody z `QueriesRelationships`, których domknięcie dostaje zapytanie o model relacji. */
    private const METODY = [
        'has', 'orHas', 'doesntHave', 'orDoesntHave',
        'whereHas', 'orWhereHas', 'whereDoesntHave', 'orWhereDoesntHave',
        'withWhereHas',
    ];

    public function isMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return $this->obslugiwana($methodReflection, $parameter);
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return $this->obslugiwana($methodReflection, $parameter);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, ParameterReflection $parameter, Scope $scope): ?Type
    {
        $wywolujacy = $scope->getType($methodCall->var);

        $model = $wywolujacy->getTemplateType(Builder::class, 'TModel');

        if (! $this->toKonkretnyModel($model)) {
            $model = $wywolujacy->getTemplateType(Relation::class, 'TRelatedModel');
        }

        return $this->typDomkniecia($model, $methodCall->getArgs()[0]->value ?? null, $scope);
    }

    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, ParameterReflection $parameter, Scope $scope): ?Type
    {
        if (! $methodCall->class instanceof Name) {
            return null;
        }

        $model = new ObjectType($scope->resolveName($methodCall->class));

        return $this->typDomkniecia($model, $methodCall->getArgs()[0]->value ?? null, $scope);
    }

    private function obslugiwana(MethodReflection $metoda, ParameterReflection $parametr): bool
    {
        return in_array($metoda->getName(), self::METODY, true)
            && $parametr->getName() === 'callback';
    }

    private function typDomkniecia(Type $model, ?Expr $relacja, Scope $scope): ?Type
    {
        if ($relacja === null) {
            return null;
        }

        $napisy = $scope->getType($relacja)->getConstantStrings();

        if (count($napisy) !== 1) {
            return null;
        }

        foreach (explode('.', $napisy[0]->getValue()) as $odcinek) {
            $model = $this->modelRelacji($model, $odcinek, $scope);

            if ($model === null) {
                return null;
            }
        }

        return new ClosureType([$this->parametr(new GenericObjectType(Builder::class, [$model]))], new MixedType);
    }

    /**
     * Własna, minimalna implementacja zamiast `NativeParameterReflection` —
     * tamta klasa nie jest objęta obietnicą zgodności PHPStana, a interfejs
     * `ParameterReflection` jest (`@api`).
     */
    private function parametr(Type $typ): ParameterReflection
    {
        return new class($typ) implements ParameterReflection
        {
            public function __construct(private readonly Type $typ) {}

            public function getName(): string
            {
                return 'query';
            }

            public function isOptional(): bool
            {
                return false;
            }

            public function getType(): Type
            {
                return $this->typ;
            }

            public function passedByReference(): PassedByReference
            {
                return PassedByReference::createNo();
            }

            public function isVariadic(): bool
            {
                return false;
            }

            public function getDefaultValue(): ?Type
            {
                return null;
            }
        };
    }

    private function modelRelacji(Type $model, string $nazwa, Scope $scope): ?Type
    {
        if (! $this->toKonkretnyModel($model) || ! $model->hasMethod($nazwa)->yes()) {
            return null;
        }

        $zwracany = $model->getMethod($nazwa, $scope)->getVariants()[0]->getReturnType();
        $docelowy = $zwracany->getTemplateType(Relation::class, 'TRelatedModel');

        return $this->toKonkretnyModel($docelowy) ? $docelowy : null;
    }

    private function toKonkretnyModel(Type $typ): bool
    {
        $klasy = $typ->getObjectClassNames();

        return count($klasy) === 1
            && $klasy[0] !== Model::class
            && (new ObjectType(Model::class))->isSuperTypeOf($typ)->yes();
    }
}
