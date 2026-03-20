# Architecture: phpstan-webmozart-assert

## Purpose

A PHPStan extension that teaches PHPStan how webmozart/assert's `Assert::*` static methods
narrow types. After `Assert::integer($x)`, PHPStan understands `$x` is `int`. After
`Assert::notNull($x)`, null is excluded from its type. The extension also supports
`nullOr*`, `all*`, and `allNullOr*` method prefixes.

## Directory Structure

```
src/Type/WebMozartAssert/
  Assert_Type_Specifying_Extension.php  # The single main class implementing the extension
stubs/
  Assert.stub  # PHPStan stub overriding Assert method signatures for precise generics
tests/Type/WebMozartAssert/
  Assert_Type_Specifying_Extension_Test.php             # Core type inference tests
  Assert_Type_Specifying_Extension_Test_Bleeding_Edge.php  # Tests requiring bleeding edge
  Impossible_Check_Type_Method_Call_Rule_Test.php       # Tests for always-true/false assertion detection
  Method_Return_Type_Rule_Test.php                      # Return type rule tests
  data/                                                 # PHP fixture files for tests
extension.neon  # Registers the extension as a StaticMethodTypeSpecifyingExtension
```

## Key Design Decisions

### Translation to Equivalent PHP Expressions

Rather than implementing custom type narrowing logic, the extension translates each
`Assert::*` assertion into an equivalent PHP expression that PHPStan's built-in type
specifier already understands. For example:

- `Assert::integer($a)` → `is_int($a)`
- `Assert::stringNotEmpty($a)` → `is_string($a) && $a !== ''`
- `Assert::isInstanceOf($a, Foo::class)` → `$a instanceof Foo`

This approach reuses PHPStan's existing type narrowing machinery and minimizes the
extension's complexity.

### Method Prefix Support

The extension dynamically handles three method prefixes:
- `nullOr*` — narrows to `T|null` (pass-through for null, narrow to T for non-null)
- `all*` — narrows each element of an iterable (array<mixed> → array<T>)
- `allNullOr*` — combination of the above

The prefix dispatching is done in `getExpressionResolvers()` which strips the prefix
and delegates to the unprefixed resolver with appropriate wrapping.

### Single Class Architecture

The entire extension is a single class (`Assert_Type_Specifying_Extension`) because the
problem domain maps cleanly to one responsibility: return `SpecifiedTypes` for any
`Assert::*` call. Splitting by assertion category would add indirection without benefit.

## Extension Points

No public extension points. The extension covers all standard webmozart/assert methods.
For custom assertion classes that extend `Assert`, configure PHPStan's `typeSpecifier`
with a custom extension implementing `StaticMethodTypeSpecifyingExtension`.

## Dependency Flow

```
extension.neon
  └─ Assert_Type_Specifying_Extension
       implements StaticMethodTypeSpecifyingExtension + TypeSpecifierAwareExtension
       └─ TypeSpecifier (injected by PHPStan) — performs the actual type narrowing
```
