<?php

declare (strict_types=1);
namespace Php_Stan\Type\Web_Mozart_Assert;

use function array_key_exists;
use function array_map;
use function array_reduce;
use function array_shift;
use ArrayAccess;
use Closure;
use function count;
use Countable;
use function is_array;
use function lcfirst;
use Php_Parser\Node\Arg;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Expr\Array_;
use Php_Parser\Node\Expr\Array_Dim_Fetch;
use Php_Parser\Node\Expr\Binary_Op;
use Php_Parser\Node\Expr\Binary_Op\Boolean_And;
use Php_Parser\Node\Expr\Binary_Op\Boolean_Or;
use Php_Parser\Node\Expr\Binary_Op\Equal;
use Php_Parser\Node\Expr\Binary_Op\Greater;
use Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal;
use Php_Parser\Node\Expr\Binary_Op\Identical;
use Php_Parser\Node\Expr\Binary_Op\Not_Identical;
use Php_Parser\Node\Expr\Binary_Op\Smaller;
use Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal;
use Php_Parser\Node\Expr\Boolean_Not;
use Php_Parser\Node\Expr\Cast\Int_;
use Php_Parser\Node\Expr\Const_Fetch;
use Php_Parser\Node\Expr\Func_Call;
use Php_Parser\Node\Expr\Instanceof_;
use Php_Parser\Node\Expr\Static_Call;
use Php_Parser\Node\Name;
use Php_Parser\Node\Scalar\L_Number;
use Php_Parser\Node\Scalar\String_;
use Php_Stan\Analyser\Scope;
use Php_Stan\Analyser\Specified_Types;
use Php_Stan\Analyser\Type_Specifier;
use Php_Stan\Analyser\Type_Specifier_Aware_Extension;
use Php_Stan\Analyser\Type_Specifier_Context;
use Php_Stan\Reflection\Method_Reflection;
use Php_Stan\Reflection\Reflection_Provider;
use Php_Stan\Should_Not_Happen_Exception;
use Php_Stan\Type\Array_Type;
use Php_Stan\Type\Constant\Constant_Array_Type_Builder;
use Php_Stan\Type\Constant\Constant_Boolean_Type;
use Php_Stan\Type\Iterable_Type;
use Php_Stan\Type\Mixed_Type;
use Php_Stan\Type\Never_Type;
use Php_Stan\Type\Static_Method_Type_Specifying_Extension;
use Php_Stan\Type\String_Type;
use Php_Stan\Type\Type;
use Php_Stan\Type\Type_Combinator;
use Reflection_Object;
use function substr;
use Traversable;
class Assert_Type_Specifying_Extension implements Static_Method_Type_Specifying_Extension, Type_Specifier_Aware_Extension
{
    /** @var Closure[] */
    private array $resolvers;
    private Reflection_Provider $reflection_provider;
    private Type_Specifier $type_specifier;
    public function __construct(Reflection_Provider $reflection_provider)
    {
        $this->reflection_provider = $reflection_provider;
    }
    public function set_type_specifier(Type_Specifier $type_specifier): void
    {
        $this->type_specifier = $type_specifier;
    }
    public function get_class(): string
    {
        return 'Webmozart\Assert\Assert';
    }
    public function is_static_method_supported(Method_Reflection $static_method_reflection, Static_Call $node, Type_Specifier_Context $context): bool
    {
        if (substr($static_method_reflection->get_name(), 0, 6) === 'allNot') {
            $methods = ['allNotInstanceOf' => 2, 'allNotNull' => 1, 'allNotSame' => 2];
            return array_key_exists($static_method_reflection->get_name(), $methods) && count($node->get_args()) >= $methods[$static_method_reflection->get_name()];
        }
        $trimmed_name = self::trim_name($static_method_reflection->get_name());
        $resolvers = $this->get_expression_resolvers();
        if (!array_key_exists($trimmed_name, $resolvers)) {
            return false;
        }
        $resolver = $resolvers[$trimmed_name];
        $resolver_reflection = new Reflection_Object(Closure::from_callable($resolver));
        return count($node->get_args()) >= count($resolver_reflection->get_method('__invoke')->get_parameters()) - 1;
    }
    private static function trim_name(string $name): string
    {
        if (substr($name, 0, 9) === 'allNullOr') {
            $name = substr($name, 9);
        } elseif (substr($name, 0, 6) === 'nullOr') {
            $name = substr($name, 6);
        } elseif (substr($name, 0, 3) === 'all') {
            $name = substr($name, 3);
        }
        return lcfirst($name);
    }
    public function specify_types(Method_Reflection $static_method_reflection, Static_Call $node, Scope $scope, Type_Specifier_Context $context): Specified_Types
    {
        if (substr($static_method_reflection->get_name(), 0, 9) === 'allNullOr') {
            return $this->handle_all($static_method_reflection->get_name(), $node, $scope, static fn(Type $type): \Php_Stan\Type\Type => Type_Combinator::add_null($type));
        }
        if (substr($static_method_reflection->get_name(), 0, 6) === 'allNot') {
            return $this->handle_all_not($static_method_reflection->get_name(), $node, $scope);
        }
        if (substr($static_method_reflection->get_name(), 0, 3) === 'all') {
            return $this->handle_all($static_method_reflection->get_name(), $node, $scope);
        }
        [$expr, $root_expr] = self::create_expression($scope, $static_method_reflection->get_name(), $node->get_args());
        if ($expr === null) {
            return new Specified_Types([], []);
        }
        $specified_types = $this->type_specifier->specify_types_in_condition($scope, $expr, Type_Specifier_Context::create_truthy())->set_root_expr($root_expr ?? $expr);
        return $this->specify_root_expr_if_set($root_expr, $scope, $specified_types);
    }
    /**
     * @param Arg[] $args
     * @return array{?Expr, ?Expr}
     */
    private function create_expression(Scope $scope, string $name, array $args): array
    {
        $trimmed_name = self::trim_name($name);
        $resolvers = $this->get_expression_resolvers();
        $resolver = $resolvers[$trimmed_name];
        $resolver_result = $resolver($scope, ...$args);
        if (is_array($resolver_result)) {
            [$expr, $root_expr] = $resolver_result;
        } else {
            $expr = $resolver_result;
            $root_expr = null;
        }
        if ($expr === null) {
            return [null, null];
        }
        if (substr($name, 0, 6) === 'nullOr') {
            $expr = new Boolean_Or($expr, new Identical($args[0]->value, new Const_Fetch(new Name('null'))));
        }
        return [$expr, $root_expr];
    }
    /**
     * @return array<string, callable(Scope, Arg...): (Expr|array{?Expr, ?Expr}|null)>
     */
    private function get_expression_resolvers(): array
    {
        if (!isset($this->resolvers)) {
            $this->resolvers = ['integer' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_int'), [$value]), 'positiveInteger' => static fn(Scope $scope, Arg $value): Expr => new Boolean_And(new Func_Call(new Name('is_int'), [$value]), new Greater($value->value, new L_Number(0))), 'string' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_string'), [$value]), 'stringNotEmpty' => static fn(Scope $scope, Arg $value): Expr => new Boolean_And(new Func_Call(new Name('is_string'), [$value]), new Not_Identical($value->value, new String_(''))), 'float' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_float'), [$value]), 'integerish' => static fn(Scope $scope, Arg $value): Expr => new Boolean_And(new Func_Call(new Name('is_numeric'), [$value]), new Equal($value->value, new Int_($value->value))), 'numeric' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_numeric'), [$value]), 'natural' => static fn(Scope $scope, Arg $value): Expr => new Boolean_And(new Func_Call(new Name('is_int'), [$value]), new Greater_Or_Equal($value->value, new L_Number(0))), 'boolean' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_bool'), [$value]), 'scalar' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_scalar'), [$value]), 'object' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_object'), [$value]), 'resource' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_resource'), [$value]), 'isCallable' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_callable'), [$value]), 'isArray' => static fn(Scope $scope, Arg $value): Expr => new Func_Call(new Name('is_array'), [$value]), 'isTraversable' => fn(Scope $scope, Arg $value): Expr => $this->resolvers['isIterable']($scope, $value), 'isIterable' => static fn(Scope $scope, Arg $expr): Expr => new Boolean_Or(new Func_Call(new Name('is_array'), [$expr]), new Instanceof_($expr->value, new Name(Traversable::class))), 'isList' => static fn(Scope $scope, Arg $expr): Expr => new Boolean_And(new Func_Call(new Name('is_array'), [$expr]), new Identical($expr->value, new Func_Call(new Name('array_values'), [$expr]))), 'isNonEmptyList' => fn(Scope $scope, Arg $expr): Expr => new Boolean_And($this->resolvers['isList']($scope, $expr), new Not_Identical($expr->value, new Array_())), 'isMap' => static fn(Scope $scope, Arg $expr): Expr => new Boolean_And(new Func_Call(new Name('is_array'), [$expr]), new Identical(new Func_Call(new Name('array_filter'), [$expr, new Arg(new String_('is_string')), new Arg(new Const_Fetch(new Name('ARRAY_FILTER_USE_KEY')))]), $expr->value)), 'isNonEmptyMap' => fn(Scope $scope, Arg $expr): Expr => new Boolean_And($this->resolvers['isMap']($scope, $expr), new Not_Identical($expr->value, new Array_())), 'isCountable' => static fn(Scope $scope, Arg $expr): Expr => new Boolean_Or(new Func_Call(new Name('is_array'), [$expr]), new Instanceof_($expr->value, new Name(Countable::class))), 'isInstanceOf' => static function (Scope $scope, Arg $expr, Arg $class): ?Expr {
                $class_type = $scope->get_type($class->value);
                $class_names = $class_type->get_object_type_or_class_string_object_type()->get_object_class_names();
                if (count($class_names) !== 0) {
                    return self::implode_expr(array_map(static fn(string $class_name): Expr => new Instanceof_($expr->value, new Name($class_name)), $class_names), Boolean_Or::class);
                }
                return new Func_Call(new Name('is_object'), [$expr]);
            }, 'isInstanceOfAny' => fn(Scope $scope, Arg $expr, Arg $classes): ?Expr => self::build_any_of_expr($scope, $expr, $classes, $this->resolvers['isInstanceOf']), 'notInstanceOf' => static function (Scope $scope, Arg $expr, Arg $class): ?Expr {
                $class_type = $scope->get_type($class->value);
                $class_names = $class_type->get_object_type_or_class_string_object_type()->get_object_class_names();
                if (count($class_names) !== 0) {
                    $result = self::implode_expr(array_map(static fn(string $class_name): Expr => new Instanceof_($expr->value, new Name($class_name)), $class_names), Boolean_Or::class);
                    if ($result !== null) {
                        return new Boolean_Not($result);
                    }
                }
                return null;
            }, 'isAOf' => static function (Scope $scope, Arg $expr, Arg $class): Expr {
                $expr_type = $scope->get_type($expr->value);
                $allow_string = (new String_Type())->is_super_type_of($expr_type)->yes();
                return new Func_Call(new Name('is_a'), [$expr, $class, new Arg(new Const_Fetch(new Name($allow_string ? 'true' : 'false')))]);
            }, 'isAnyOf' => fn(Scope $scope, Arg $value, Arg $classes): ?Expr => self::build_any_of_expr($scope, $value, $classes, $this->resolvers['isAOf']), 'isNotA' => fn(Scope $scope, Arg $value, Arg $class): Expr => new Boolean_Not($this->resolvers['isAOf']($scope, $value, $class)), 'implementsInterface' => function (Scope $scope, Arg $expr, Arg $class): ?Expr {
                $class_type = $scope->get_type($class->value)->get_class_string_object_type();
                $class_names = $class_type->get_object_class_names();
                if (count($class_names) !== 1) {
                    return null;
                }
                if (!$this->reflection_provider->has_class($class_names[0])) {
                    return null;
                }
                $class_reflection = $this->reflection_provider->get_class($class_names[0]);
                if (!$class_reflection->is_interface()) {
                    return new Const_Fetch(new Name('false'));
                }
                return $this->resolvers['subclassOf']($scope, $expr, $class);
            }, 'keyExists' => static fn(Scope $scope, Arg $array, Arg $key): Expr => new Func_Call(new Name('array_key_exists'), [$key, $array]), 'keyNotExists' => fn(Scope $scope, Arg $array, Arg $key): Expr => new Boolean_Not($this->resolvers['keyExists']($scope, $array, $key)), 'validArrayKey' => static fn(Scope $scope, Arg $value): Expr => new Boolean_Or(new Func_Call(new Name('is_int'), [$value]), new Func_Call(new Name('is_string'), [$value])), 'true' => static fn(Scope $scope, Arg $expr): Expr => new Identical($expr->value, new Const_Fetch(new Name('true'))), 'false' => static fn(Scope $scope, Arg $expr): Expr => new Identical($expr->value, new Const_Fetch(new Name('false'))), 'null' => static fn(Scope $scope, Arg $expr): Expr => new Identical($expr->value, new Const_Fetch(new Name('null'))), 'notFalse' => static fn(Scope $scope, Arg $expr): Expr => new Not_Identical($expr->value, new Const_Fetch(new Name('false'))), 'notNull' => static fn(Scope $scope, Arg $expr): Expr => new Not_Identical($expr->value, new Const_Fetch(new Name('null'))), 'eq' => static fn(Scope $scope, Arg $value, Arg $value2): Expr => new Equal($value->value, $value2->value), 'notEq' => fn(Scope $scope, Arg $value, Arg $value2): Expr => new Boolean_Not($this->resolvers['eq']($scope, $value, $value2)), 'same' => static fn(Scope $scope, Arg $value1, Arg $value2): Expr => new Identical($value1->value, $value2->value), 'notSame' => static fn(Scope $scope, Arg $value1, Arg $value2): Expr => new Not_Identical($value1->value, $value2->value), 'greaterThan' => static fn(Scope $scope, Arg $value, Arg $limit): Expr => new Greater($value->value, $limit->value), 'greaterThanEq' => static fn(Scope $scope, Arg $value, Arg $limit): Expr => new Greater_Or_Equal($value->value, $limit->value), 'lessThan' => static fn(Scope $scope, Arg $value, Arg $limit): Expr => new Smaller($value->value, $limit->value), 'lessThanEq' => static fn(Scope $scope, Arg $value, Arg $limit): Expr => new Smaller_Or_Equal($value->value, $limit->value), 'range' => static fn(Scope $scope, Arg $value, Arg $min, Arg $max): Expr => new Boolean_And(new Greater_Or_Equal($value->value, $min->value), new Smaller_Or_Equal($value->value, $max->value)), 'subclassOf' => static fn(Scope $scope, Arg $expr, Arg $class): Expr => new Func_Call(new Name('is_subclass_of'), [new Arg($expr->value), $class]), 'classExists' => static fn(Scope $scope, Arg $class): Expr => new Func_Call(new Name('class_exists'), [$class]), 'interfaceExists' => static fn(Scope $scope, Arg $class): Expr => new Func_Call(new Name('interface_exists'), [$class]), 'count' => static fn(Scope $scope, Arg $array, Arg $number): Expr => new Identical(new Func_Call(new Name('count'), [$array]), $number->value), 'minCount' => static fn(Scope $scope, Arg $array, Arg $min): Expr => new Greater_Or_Equal(new Func_Call(new Name('count'), [$array]), $min->value), 'maxCount' => static fn(Scope $scope, Arg $array, Arg $max): Expr => new Smaller_Or_Equal(new Func_Call(new Name('count'), [$array]), $max->value), 'countBetween' => static fn(Scope $scope, Arg $array, Arg $min, Arg $max): Expr => new Boolean_And(new Greater_Or_Equal(new Func_Call(new Name('count'), [$array]), $min->value), new Smaller_Or_Equal(new Func_Call(new Name('count'), [$array]), $max->value)), 'length' => static fn(Scope $scope, Arg $value, Arg $length): Expr => new Boolean_And(new Func_Call(new Name('is_string'), [$value]), new Identical(new Func_Call(new Name('strlen'), [$value]), $length->value)), 'minLength' => static fn(Scope $scope, Arg $value, Arg $min): Expr => new Boolean_And(new Func_Call(new Name('is_string'), [$value]), new Greater_Or_Equal(new Func_Call(new Name('strlen'), [$value]), $min->value)), 'maxLength' => static fn(Scope $scope, Arg $value, Arg $max): Expr => new Boolean_And(new Func_Call(new Name('is_string'), [$value]), new Smaller_Or_Equal(new Func_Call(new Name('strlen'), [$value]), $max->value)), 'lengthBetween' => static fn(Scope $scope, Arg $value, Arg $min, Arg $max): Expr => new Boolean_And(new Func_Call(new Name('is_string'), [$value]), new Boolean_And(new Greater_Or_Equal(new Func_Call(new Name('strlen'), [$value]), $min->value), new Smaller_Or_Equal(new Func_Call(new Name('strlen'), [$value]), $max->value))), 'inArray' => static fn(Scope $scope, Arg $needle, Arg $array): Expr => new Func_Call(new Name('in_array'), [$needle, $array, new Arg(new Const_Fetch(new Name('true')))]), 'oneOf' => fn(Scope $scope, Arg $needle, Arg $array): Expr => $this->resolvers['inArray']($scope, $needle, $array), 'methodExists' => static fn(Scope $scope, Arg $object, Arg $method): Expr => new Func_Call(new Name('method_exists'), [$object, $method]), 'propertyExists' => static fn(Scope $scope, Arg $object, Arg $property): Expr => new Func_Call(new Name('property_exists'), [$object, $property]), 'isArrayAccessible' => static fn(Scope $scope, Arg $expr): Expr => new Boolean_Or(new Func_Call(new Name('is_array'), [$expr]), new Instanceof_($expr->value, new Name(ArrayAccess::class)))];
            foreach (['contains', 'startsWith', 'endsWith'] as $name) {
                $this->resolvers[$name] = static function (Scope $scope, Arg $value, Arg $sub_string) use ($name): array {
                    if ($scope->get_type($sub_string->value)->is_non_empty_string()->yes()) {
                        return self::create_is_non_empty_string_and_something_expr_pair($name, [$value, $sub_string]);
                    }
                    $expr = new Func_Call(new Name('is_string'), [$value]);
                    $root_expr = new Boolean_And($expr, new Func_Call(new Name('FAUX_FUNCTION_ ' . $name), [$value, $sub_string]));
                    return [$expr, $root_expr];
                };
            }
            $assertions_resulting_at_least_in_non_empty_string = ['startsWithLetter', 'unicodeLetters', 'alpha', 'digits', 'alnum', 'lower', 'upper', 'uuid', 'ip', 'ipv4', 'ipv6', 'email', 'notWhitespaceOnly'];
            foreach ($assertions_resulting_at_least_in_non_empty_string as $name) {
                $this->resolvers[$name] = static fn(Scope $scope, Arg $value): array => self::create_is_non_empty_string_and_something_expr_pair($name, [$value]);
            }
        }
        return $this->resolvers;
    }
    private function handle_all_not(string $method_name, Static_Call $node, Scope $scope): Specified_Types
    {
        if ($method_name === 'allNotNull') {
            return $this->all_array_or_iterable($scope, $node->get_args()[0]->value, static fn(Type $type): Type => Type_Combinator::remove_null($type), null);
        }
        if ($method_name === 'allNotInstanceOf') {
            $class_type = $scope->get_type($node->get_args()[1]->value);
            $class_name_type = $class_type->get_object_type_or_class_string_object_type();
            $class_names = $class_name_type->get_object_class_names();
            if (count($class_names) !== 1) {
                return new Specified_Types([], []);
            }
            return $this->all_array_or_iterable($scope, $node->get_args()[0]->value, static fn(Type $type): Type => Type_Combinator::remove($type, $class_name_type), null);
        }
        if ($method_name === 'allNotSame') {
            $value_type = $scope->get_type($node->get_args()[1]->value);
            return $this->all_array_or_iterable($scope, $node->get_args()[0]->value, static fn(Type $type): Type => Type_Combinator::remove($type, $value_type), null);
        }
        throw new Should_Not_Happen_Exception();
    }
    /**
     * @param callable(Type): Type|null $typeModifier
     */
    private function handle_all(string $method_name, Static_Call $node, Scope $scope, ?callable $type_modifier = null): Specified_Types
    {
        $args = $node->get_args();
        $args[0] = new Arg(new Array_Dim_Fetch($args[0]->value, new L_Number(0)));
        [$expr, $root_expr] = self::create_expression($scope, $method_name, $args);
        if ($expr === null) {
            return new Specified_Types();
        }
        $specified_types = $this->type_specifier->specify_types_in_condition($scope, $expr, Type_Specifier_Context::create_truthy())->set_root_expr($root_expr ?? $expr);
        $sure_not_types = $specified_types->get_sure_not_types();
        foreach ($specified_types->get_sure_types() as $expr_str => [$expr_node, $type]) {
            if ($expr_node !== $args[0]->value) {
                continue;
            }
            $type = Type_Combinator::remove($type, $sure_not_types[$expr_str][1] ?? new Never_Type());
            if ($type_modifier !== null) {
                $type = $type_modifier($type);
            }
            return $this->all_array_or_iterable($scope, $node->get_args()[0]->value, static fn(): Type => $type, $root_expr);
        }
        return $specified_types;
    }
    private function all_array_or_iterable(Scope $scope, Expr $expr, Closure $type_callback, ?Expr $root_expr): Specified_Types
    {
        $current_type = Type_Combinator::intersect($scope->get_type($expr), new Iterable_Type(new Mixed_Type(), new Mixed_Type()));
        $array_types = $current_type->get_arrays();
        if (count($array_types) > 0) {
            $new_array_types = [];
            foreach ($array_types as $array_type) {
                $constant_arrays = $array_type->get_constant_arrays();
                if (count($constant_arrays) === 1) {
                    $builder = Constant_Array_Type_Builder::create_empty();
                    foreach ($constant_arrays[0]->get_key_types() as $i => $key_type) {
                        $value_type = $type_callback($constant_arrays[0]->get_value_types()[$i]);
                        if ($value_type instanceof Never_Type) {
                            continue 2;
                        }
                        $builder->set_offset_value_type($key_type, $value_type, $constant_arrays[0]->is_optional_key($i));
                    }
                    $new_array_types[] = $builder->get_array();
                } else {
                    $item_type = $type_callback($array_type->get_item_type());
                    if ($item_type instanceof Never_Type) {
                        continue;
                    }
                    $new_array_types[] = new Array_Type($array_type->get_key_type(), $item_type);
                }
            }
            $specified_type = Type_Combinator::union(...$new_array_types);
        } elseif ((new Iterable_Type(new Mixed_Type(), new Mixed_Type()))->is_super_type_of($current_type)->yes()) {
            $item_type = $type_callback($current_type->get_iterable_value_type());
            if ($item_type instanceof Never_Type) {
                $specified_type = $item_type;
            } else {
                $specified_type = new Iterable_Type($current_type->get_iterable_key_type(), $item_type);
            }
        } else {
            return new Specified_Types([], []);
        }
        $specified_types = $this->type_specifier->create($expr, $specified_type, Type_Specifier_Context::create_truthy(), $scope)->set_root_expr($root_expr);
        return $this->specify_root_expr_if_set($root_expr, $scope, $specified_types);
    }
    /**
     * @param Expr[] $expressions
     * @param class-string<BinaryOp> $binaryOp
     */
    private static function implode_expr(array $expressions, string $binary_op): ?Expr
    {
        $first_expression = array_shift($expressions);
        if ($first_expression === null) {
            return null;
        }
        return array_reduce($expressions, static fn(Expr $carry, Expr $item): object => new $binary_op($carry, $item), $first_expression);
    }
    private static function build_any_of_expr(Scope $scope, Arg $value, Arg $items, callable $resolver): ?Expr
    {
        if (!$items->value instanceof Array_) {
            return null;
        }
        $resolvers = [];
        foreach ($items->value->items as $key => $item) {
            $resolved = $resolver($scope, $value, new Arg($item->value));
            if ($resolved === null) {
                continue;
            }
            $resolvers[$key] = $resolved;
        }
        return self::implode_expr($resolvers, Boolean_Or::class);
    }
    /**
     * @param Arg[] $args
     * @return array{Expr, Expr}
     */
    private static function create_is_non_empty_string_and_something_expr_pair(string $name, array $args): array
    {
        $expr = new Boolean_And(new Func_Call(new Name('is_string'), [$args[0]]), new Not_Identical($args[0]->value, new String_('')));
        $root_expr = new Boolean_And($expr, new Func_Call(new Name('FAUX_FUNCTION_ ' . $name), $args));
        return [$expr, $root_expr];
    }
    private function specify_root_expr_if_set(?Expr $root_expr, Scope $scope, Specified_Types $specified_types): Specified_Types
    {
        if ($root_expr === null) {
            return $specified_types;
        }
        // Makes consecutive calls with a rootExpr adding unknown info via FAUX_FUNCTION evaluate to true
        return $specified_types->union_with($this->type_specifier->create($root_expr, new Constant_Boolean_Type(true), Type_Specifier_Context::create_truthy(), $scope));
    }
}