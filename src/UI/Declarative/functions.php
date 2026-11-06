<?php
// The views, modifiers' enums and colors of myphp's declarative UI, as SwiftUI's, in the global namespace as
// SwiftUI's need no import: VStack, Text, Button… (docs/declarative.md). Loaded with the first view file
// (ViewFile), and by what renders views; their classes are MyPHP\UI\Declarative's.
declare(strict_types=1);

use MyPHP\UI\Declarative as D;
use MyPHP\UI\{Image as UIImage, Node, SVG, Theme};

// ---- enums ---------------------------------------------------------------------------------------------------

/** SwiftUI's text styles, which follow the system's text size; Font::system() a size of its own. */
enum Font
{
    case largeTitle; case title; case title2; case title3; case headline; case subheadline;
    case body; case callout; case footnote; case caption; case caption2;

    /** A font of $size points, of $weight; monospaced with $design. */
    public static function system(float $size, Weight $weight = Weight::regular, FontDesign $design = FontDesign::default): D\FontSpec
    {
        return new D\FontSpec(null, $size, $weight, $design === FontDesign::monospaced);
    }

    /** @internal */
    public function spec(): D\FontSpec
    {
        return new D\FontSpec(match ($this) {
            self::largeTitle => MyPHP\UI\Font::LARGE_TITLE, self::title => MyPHP\UI\Font::TITLE1, self::title2 => MyPHP\UI\Font::TITLE2,
            self::title3 => MyPHP\UI\Font::TITLE3, self::headline => MyPHP\UI\Font::HEADLINE, self::subheadline => MyPHP\UI\Font::SUBHEADLINE,
            self::body => MyPHP\UI\Font::BODY, self::callout => MyPHP\UI\Font::CALLOUT, self::footnote => MyPHP\UI\Font::FOOTNOTE,
            self::caption => MyPHP\UI\Font::CAPTION1, self::caption2 => MyPHP\UI\Font::CAPTION2,
        });
    }
}

enum FontDesign { case default; case monospaced; }

/** SwiftUI's Font.Weight, as CSS's numbers. */
enum Weight: int
{
    case ultraLight = 100; case thin = 200; case light = 300; case regular = 400; case medium = 500;
    case semibold = 600; case bold = 700; case heavy = 800; case black = 900;
}

/** SwiftUI's system colors, light and dark (UIKit's), and the theme's: primary the text, secondary the muted text, accentColor the accent. */
enum Color
{
    case primary; case secondary; case accentColor; case background;
    case red; case orange; case yellow; case green; case mint; case teal; case cyan; case blue; case indigo; case purple; case pink; case brown;
    case gray; case white; case black; case clear;

    /** A color of the app's own, 0xRRGGBB. */
    public static function rgb(int $rgb, float $opacity = 1.0): D\ColorValue
    {
        return new D\ColorValue(null, $rgb, $opacity);
    }

    /** This color with $opacity. */
    public function opacity(float $opacity): D\ColorValue
    {
        return new D\ColorValue($this, 0, $opacity);
    }

    /** @internal the color as 0xRRGGBB in theme $t */
    public function resolve(Theme $t): int
    {
        $d = $t->dark;
        return match ($this) {
            self::primary => $t->text, self::secondary => $t->textMuted, self::accentColor => $t->accent, self::background => $t->background,
            self::red => $d ? 0xff453a : 0xff3b30, self::orange => $d ? 0xff9f0a : 0xff9500, self::yellow => $d ? 0xffd60a : 0xffcc00,
            self::green => $d ? 0x30d158 : 0x34c759, self::mint => $d ? 0x63e6e2 : 0x00c7be, self::teal => $d ? 0x40c8e0 : 0x30b0c7,
            self::cyan => $d ? 0x64d2ff : 0x32ade6, self::blue => $d ? 0x0a84ff : 0x007aff, self::indigo => $d ? 0x5e5ce6 : 0x5856d6,
            self::purple => $d ? 0xbf5af2 : 0xaf52de, self::pink => $d ? 0xff375f : 0xff2d55, self::brown => $d ? 0xac8e68 : 0xa2845e,
            self::gray => 0x8e8e93, self::white => 0xffffff, self::black => 0x000000, self::clear => $t->background,
        };
    }
}

/** SwiftUI's Alignment: where a view goes in the room a frame or a ZStack gives it. */
enum Alignment
{
    case topLeading; case top; case topTrailing; case leading; case center; case trailing; case bottomLeading; case bottom; case bottomTrailing;

    /** @internal places $n in the room around it ($self: as a ZStack's child, else its content in it) */
    public function place(Node $n, bool $self = false): void
    {
        [$h, $v] = match ($this) {
            self::topLeading => [Node::START, Node::START], self::top => [Node::CENTER, Node::START], self::topTrailing => [Node::END, Node::START],
            self::leading => [Node::START, Node::CENTER], self::center => [Node::CENTER, Node::CENTER], self::trailing => [Node::END, Node::CENTER],
            self::bottomLeading => [Node::START, Node::END], self::bottom => [Node::CENTER, Node::END], self::bottomTrailing => [Node::END, Node::END],
        };
        if ($self) { $n->justifySelf($h)->alignSelf($v); return; }
        $n->dir === Node::ROW ? $n->justify($h)->alignItems($v) : $n->alignItems($h)->justify($v);
    }
}

/** SwiftUI's HorizontalAlignment, a VStack's. */
enum HorizontalAlignment { case leading; case center; case trailing; }

/** SwiftUI's VerticalAlignment, an HStack's. */
enum VerticalAlignment { case top; case center; case bottom; }

enum TextAlignment
{
    case leading; case center; case trailing;
    /** @internal */
    public function align(): int { return match ($this) { self::leading => Node::START, self::center => Node::CENTER, self::trailing => Node::END }; }
}

/** SwiftUI's Edge.Set, for padding(). */
enum Edge
{
    case all; case horizontal; case vertical; case top; case bottom; case leading; case trailing;
    /** @internal */ public function top(): bool { return in_array($this, [self::all, self::vertical, self::top], true); }
    /** @internal */ public function bottom(): bool { return in_array($this, [self::all, self::vertical, self::bottom], true); }
    /** @internal */ public function leading(): bool { return in_array($this, [self::all, self::horizontal, self::leading], true); }
    /** @internal */ public function trailing(): bool { return in_array($this, [self::all, self::horizontal, self::trailing], true); }
}

/** SwiftUI's KeyEquivalent: the keys of keyboardShortcut() that type no character; a character is a string ('s'). */
enum KeyEquivalent
{
    case upArrow; case downArrow; case leftArrow; case rightArrow; case return; case escape; case delete; case deleteForward;
    case space; case tab; case home; case end; case pageUp; case pageDown;
    /** @internal */
    public function key(): int
    {
        return match ($this) {
            self::upArrow => MyPHP\UI\Key::UP, self::downArrow => MyPHP\UI\Key::DOWN, self::leftArrow => MyPHP\UI\Key::LEFT, self::rightArrow => MyPHP\UI\Key::RIGHT,
            self::return => MyPHP\UI\Key::RETURN, self::escape => MyPHP\UI\Key::ESCAPE, self::delete => MyPHP\UI\Key::DELETE, self::deleteForward => MyPHP\UI\Key::FORWARD_DELETE,
            self::space => MyPHP\UI\Key::SPACE, self::tab => MyPHP\UI\Key::TAB, self::home => MyPHP\UI\Key::HOME, self::end => MyPHP\UI\Key::END,
            self::pageUp => MyPHP\UI\Key::PAGE_UP, self::pageDown => MyPHP\UI\Key::PAGE_DOWN,
        };
    }
}

/** SwiftUI's EventModifiers: command is ⌘ on macOS and Ctrl elsewhere. */
enum EventModifiers
{
    case command; case shift; case option; case control;
    /** @internal @param list<self> $mods */
    public static function mask(array $mods): int
    {
        $m = 0;
        foreach ($mods as $x) { $m |= match ($x) { self::command => MyPHP\UI\Key::COMMAND, self::shift => MyPHP\UI\Key::SHIFT, self::option => MyPHP\UI\Key::OPTION, self::control => MyPHP\UI\Key::CONTROL }; }
        return $m;
    }
}

enum ButtonStyle { case automatic; case bordered; case borderedProminent; case borderless; case plain; }

enum ButtonRole { case destructive; case cancel; }

enum ToggleStyle { case automatic; case switch; case checkbox; }

/** The type of every view, for a parameter or return type: fn(): View => …. */
class_alias(D\View::class, 'View');

// ---- views ---------------------------------------------------------------------------------------------------

/** The views down a column: VStack(spacing: 12)( Text('a'), Text('b') ). */
function VStack(HorizontalAlignment $alignment = HorizontalAlignment::center, ?float $spacing = null): D\Stack
{
    return new D\Stack(Node::COLUMN, match ($alignment) { HorizontalAlignment::leading => Node::START, HorizontalAlignment::center => Node::CENTER, HorizontalAlignment::trailing => Node::END }, $spacing);
}

/** The views across a row: HStack(spacing: 8)( Text('a'), Spacer(), Button(…) ). */
function HStack(VerticalAlignment $alignment = VerticalAlignment::center, ?float $spacing = null): D\Stack
{
    return new D\Stack(Node::ROW, match ($alignment) { VerticalAlignment::top => Node::START, VerticalAlignment::center => Node::CENTER, VerticalAlignment::bottom => Node::END }, $spacing);
}

/** The views one over the other: ZStack(alignment: Alignment::bottomTrailing)( Color(…), Text('badge') ). */
function ZStack(Alignment $alignment = Alignment::center): D\ZStack
{
    return new D\ZStack($alignment);
}

function Spacer(?float $minLength = null): D\Spacer { return new D\Spacer($minLength); }

function Divider(): D\Divider { return new D\Divider(); }

function Group(): D\Group { return new D\Group(); }

function Text(string $text): D\Text { return new D\Text($text); }

/** A button: Button('Save', action: fn() => $this->save()), or with a label view: Button(action: …, label: Label('Add', systemImage: 'plus')). */
function Button(string $title = '', ?Closure $action = null, ?ButtonRole $role = null, ?D\View $label = null): D\Button
{
    return new D\Button($title, $action, $role, $label);
}

/** A switch bound to $isOn: Toggle('Wi-Fi', isOn: $this->wifi). */
function Toggle(string $title, bool &$isOn): D\Toggle
{
    return new D\Toggle($title, $isOn);
}

/** A field bound to $text: TextField('Name', text: $this->name). */
function TextField(string $title, string &$text, ?string $prompt = null, Axis $axis = Axis::horizontal): D\TextField
{
    return new D\TextField($title, $text, $prompt, false, $axis === Axis::vertical);
}

/** A field that hides what is typed: SecureField('Password', text: $this->password). */
function SecureField(string $title, string &$text, ?string $prompt = null): D\TextField
{
    return new D\TextField($title, $text, $prompt, true);
}

enum Axis { case horizontal; case vertical; }

/** A picture: Image($image), Image(file: 'photo.jpg'), Image(systemName: 'star.fill'). */
function Image(UIImage|SVG|null $image = null, ?string $file = null, ?string $systemName = null): D\ImageView
{
    if ($file !== null) { $image = str_ends_with(strtolower($file), '.svg') ? SVG::fromFile($file) : UIImage::fromFile($file); }
    return new D\ImageView($image, $systemName);
}

/** A color as a view, filling its room: Color(Color::blue), Color(Color::rgb(0x336699)). */
function Color(Color|D\ColorValue $color): D\ColorView
{
    return new D\ColorView(D\ColorValue::of($color));
}

function Rectangle(): D\Shape { return new D\Shape(D\Shape::RECTANGLE); }
function RoundedRectangle(float $cornerRadius): D\Shape { return new D\Shape(D\Shape::ROUNDED, $cornerRadius); }
function Circle(): D\Shape { return new D\Shape(D\Shape::CIRCLE); }
function Capsule(): D\Shape { return new D\Shape(D\Shape::CAPSULE); }
function Ellipse(): D\Shape { return new D\Shape(D\Shape::ELLIPSE); }

/** SwiftUI's ForEach (foreach is PHP's): Each($this->todos, id: 'id')(fn(Todo $t) => Text($t->title)). @param iterable<mixed> $items */
function Each(iterable $items, string|Closure|null $id = null): D\Each
{
    return new D\Each($items, $id);
}

// ---- collections, navigation ---------------------------------------------------------------------------------

/** SwiftUI's ScrollView: ScrollView()( … ), across with ScrollView(Axis::horizontal). */
function ScrollView(Axis $axes = Axis::vertical): D\ScrollView { return new D\ScrollView($axes); }

/**
 * SwiftUI's List (list is PHP's): of data, ListView($this->notes, id: 'id')(fn(Note $n) => Text($n->title)), only the
 * rows in view built; of views, ListView()( Section(header: 'A')( … ), … ), an inset grouped list. @param array<mixed>|null $items
 */
function ListView(array|int|null $items = null, string|Closure|null $id = null): D\ListView { return new D\ListView($items, $id); }

/** SwiftUI's LabeledContent: a title and its value, a cell in a list. */
function LabeledContent(string $title, string $value): D\LabeledContent { return new D\LabeledContent($title, $value); }

function Section(string $header = '', string $footer = ''): D\Section { return new D\Section($header, $footer); }

/** SwiftUI's Form: sections of controls, an inset grouped list. */
function Form(): D\ListView { return new D\ListView(); }

function Label(string $title, ?string $systemImage = null, ?SVG $image = null): D\Label { return new D\Label($title, $systemImage, $image); }

/** SwiftUI's NavigationStack(path:): NavigationStack(path: $this->path)( Root… )->navigationDestination(for: '/notes/{id}', destination: __DIR__ . '/pages/note.php'). */
function NavigationStack(?MyPHP\UI\Router $path = null): D\NavigationStack { return new D\NavigationStack($path); }

/** Goes to the page of $value in the NavigationStack it is in: NavigationLink('Note', value: '/notes/42'). */
function NavigationLink(string $title = '', string $value = '/', ?D\View $label = null): D\NavigationLink { return new D\NavigationLink($title, $value, $label); }

/** SwiftUI's TabView(selection:): TabView(selection: $this->tab)( Tab('Home', image: $icon, value: 0)( … ), … ). */
function TabView(mixed &$selection): D\TabView { return new D\TabView($selection); }

function Tab(string $title, SVG $image, mixed $value): D\Tab { return new D\Tab($title, $image, $value); }

/** SwiftUI's NavigationBarItem.TitleDisplayMode. */
enum TitleDisplayMode { case automatic; case inline; case large; }

/** SwiftUI's NavigationPath: a Router, of paths: append('/notes/42'), removeLast(), count, isEmpty. */
class_alias(MyPHP\UI\Router::class, 'NavigationPath');
