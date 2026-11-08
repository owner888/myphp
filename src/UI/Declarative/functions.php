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
    case primary; case secondary; case accentColor; case background; case separator;
    // iOS's semantic colors (UIColor's, Color(.secondarySystemBackground) in SwiftUI), following the theme, light or dark:
    // the backgrounds, a surface over them (a field's, a card's), those of grouped lists, the text, links.
    case systemBackground; case secondarySystemBackground; case systemGroupedBackground; case secondarySystemGroupedBackground;
    case tertiarySystemBackground; case tertiarySystemGroupedBackground;
    case label; case secondaryLabel; case link;
    // iOS's grays, from systemGray to the lightest, systemGray6 (in the dark the darkest), as Apple gives them.
    case systemGray; case systemGray2; case systemGray3; case systemGray4; case systemGray5; case systemGray6;
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
            self::primary => $t->text, self::secondary => $t->textMuted, self::accentColor => $t->accent, self::background => $t->background, self::separator => $t->border,
            self::systemBackground => $t->background, self::secondarySystemBackground => $t->surface,
            self::systemGroupedBackground => $t->groupBackground ?? $t->surface, self::secondarySystemGroupedBackground => $t->groupSurface ?? $t->background, // a grouped list's page and its sections, as the theme has them: a phone's in the dark the black page and its dark gray sections, as iOS's
            // what sits on those (a tile in a section): iOS's third level, the gray in the light, a lighter gray in the dark; the desktop's background and surface
            self::tertiarySystemBackground => $t->groupSurface !== null ? ($d ? 0x2c2c2e : 0xffffff) : $t->background,
            self::tertiarySystemGroupedBackground => $t->groupSurface !== null ? ($d ? 0x2c2c2e : 0xf2f2f7) : $t->surface,
            self::label => $t->text, self::secondaryLabel => $t->textMuted, self::link => $t->accent,
            self::systemGray => 0x8e8e93, self::systemGray2 => $d ? 0x636366 : 0xaeaeb2, self::systemGray3 => $d ? 0x48484a : 0xc7c7cc,
            self::systemGray4 => $d ? 0x3a3a3c : 0xd1d1d6, self::systemGray5 => $d ? 0x2c2c2e : 0xe5e5ea, self::systemGray6 => $d ? 0x1c1c1e : 0xf2f2f7,
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

/** Whether the text inside can be selected and copied, as SwiftUI's textSelection(.enabled). */
enum TextSelectability { case enabled; case disabled; }

/** The appearance an app asks for with preferredColorScheme(); null follows the system. */
enum ColorScheme { case light; case dark; }

/** SwiftUI's DynamicTypeSize, iOS's text sizes: large the usual, the accessibility sizes past xxxLarge. */
enum DynamicTypeSize
{
    case xSmall; case small; case medium; case large; case xLarge; case xxLarge; case xxxLarge;
    case accessibility1; case accessibility2; case accessibility3; case accessibility4; case accessibility5;

    /** How much larger than usual (large) text is at this size: iOS's body size at it over 17. */
    public function scale(): float
    {
        return match ($this) {
            self::xSmall => 14, self::small => 15, self::medium => 16, self::large => 17, self::xLarge => 19, self::xxLarge => 21, self::xxxLarge => 23,
            self::accessibility1 => 28, self::accessibility2 => 33, self::accessibility3 => 40, self::accessibility4 => 47, self::accessibility5 => 53,
        } / 17;
    }

    /** Whether it is one of the accessibility sizes, where a row of views is better a column (SwiftUI's isAccessibilitySize). */
    public function isAccessibilitySize(): bool
    {
        return $this->scale() >= 28 / 17;
    }

    /**
     * The size the app's text is at, as SwiftUI's @Environment(\.dynamicTypeSize): the system's, or the one
     * dynamicTypeSize() chose, the nearest of the cases. A view lays itself out by it at the accessibility sizes:
     * DynamicTypeSize::current()->isAccessibilitySize() ? VStack()(…) : HStack()(…).
     */
    public static function current(): self
    {
        $scale = MyPHP\App::theme()->textScale();
        $best = self::large;
        foreach (self::cases() as $c) { if (abs($c->scale() - $scale) < abs($best->scale() - $scale)) { $best = $c; } }
        return $best;
    }
}

/** What an onKeyPress action did: took the key (handled), or left it to the view (ignored). */
enum KeyPressResult { case handled; case ignored; }

/** A key pressed, as onKeyPress gives it: its modifiers. */
final class KeyPress
{
    /** @param list<EventModifiers> $modifiers */
    public function __construct(public readonly array $modifiers) {}

    /** The press of the modifier mask $mods. @internal */
    public static function of(int $mods): self
    {
        return new self(array_values(array_filter(EventModifiers::cases(), fn(EventModifiers $m) => ($mods & EventModifiers::mask([$m])) !== 0)));
    }
}

/** How a TextField draws: plain, no face nor border, as a launcher's query; automatic and roundedBorder a field's, as SwiftUI's. */
/** How a ListView's rows draw: automatic, cells with a separator under each (iOS's); plain, rows of their content's height, rounded as they are chosen, as macOS's. */
enum ListStyle { case automatic; case plain; }

enum TextFieldStyle { case automatic; case roundedBorder; case plain; }

/** How a Toggle draws: a switch, a checkbox, a button that stays pressed; automatic is a switch on a phone and a checkbox on a Mac, as SwiftUI's. */
enum ToggleStyle { case automatic; case switch; case checkbox; case button; }

/** How a Picker draws: a pop-up menu (automatic, menu), a segmented control, radio buttons (radioGroup, inline). */
enum PickerStyle { case automatic; case menu; case segmented; case radioGroup; case inline; }

/** How a DatePicker draws: a field opening a calendar (automatic, compact, field) or the calendar (graphical). */
enum DatePickerStyle { case automatic; case compact; case field; case graphical; }

/** What a DatePicker edits: its date, its time, or both. */
enum DatePickerComponents { case date; case hourAndMinute; }

/** The type of every view, for a parameter or return type: fn(): View => …. */
class_alias(D\View::class, 'View');

/** What a GeometryReader gives its content: fn(GeometryProxy $g) => …. */
class_alias(D\GeometryProxy::class, 'GeometryProxy');
/** What a ScrollViewReader gives its content: fn(ScrollViewProxy $proxy) => …. */
class_alias(D\ScrollViewProxy::class, 'ScrollViewProxy');
/** Where a scroll is (onScrollGeometryChange), and where to scroll one (scrollPosition), as SwiftUI's. */
class_alias(D\ScrollGeometry::class, 'ScrollGeometry');
class_alias(D\ScrollPosition::class, 'ScrollPosition');
/** A binding of a value computed, as SwiftUI's Binding(get:set:): new Binding(get: fn() => …, set: fn($v) => …), given to a control. */
class_alias(D\Binding::class, 'Binding');
/** The header a Table is sorted by: its column's value key, and whether descending (Table(sortOrder:)). */
class_alias(MyPHP\UI\SortOrder::class, 'SortOrder');

/** A column of a LazyVGrid: GridItem::adaptive(minimum: 132). */
class_alias(D\GridItem::class, 'GridItem');
/** A value a chart plots, and its label: PlottableValue::value('Month', 'Mar'), as Swift Charts' .value(_:_:). */
class_alias(D\PlottableValue::class, 'PlottableValue');
/** A radius of a SectorMark: MarkDimension::ratio(0.6), inset(10), fixed(40). */
class_alias(D\MarkDimension::class, 'MarkDimension');
/** The ticks of a chart's axis: new AxisMarks(position: AxisMarkPosition::leading, desiredCount: 4, format: fn($v) => …). */
class_alias(D\AxisMarks::class, 'AxisMarks');
/** How a line is stroked: new StrokeStyle(lineWidth: 3). */
class_alias(D\StrokeStyle::class, 'StrokeStyle');
/** The moment of a TimelineView: its date, and the frame's time in nanoseconds. */
class_alias(D\TimelineViewContext::class, 'TimelineViewContext');
/** How a change goes, as SwiftUI's Animation: Animation::easeInOut(duration: 0.3), spring(duration:, bounce:), snappy(), bouncy(), linear(), ->delay(). */
class_alias(MyPHP\UI\Animation::class, 'Animation');
/** How a view comes and goes: AnyTransition::opacity(), scale(), slide(), move(edge:), ->combined(). */
class_alias(D\AnyTransition::class, 'AnyTransition');
/** A drag of the pointer on a view: ->gesture(DragGesture()->onChanged(fn(DragGestureValue $v) => …)->onEnded(…)). */
class_alias(D\DragGesture::class, 'DragGesture');
class_alias(D\DragGestureValue::class, 'DragGestureValue');
class_alias(D\CGSize::class, 'CGSize');
class_alias(D\CGPoint::class, 'CGPoint');
/** Keyframes sampled at any time: (new KeyframeTimeline(initialValue: [...], tracks: [...]))->value(time: $t). */
class_alias(D\KeyframeTimeline::class, 'KeyframeTimeline');
/** What a Canvas draws in: fill(), stroke(), withPainter(). */
class_alias(D\GraphicsContext::class, 'GraphicsContext');
/** A path to fill or stroke in a Canvas: (new Path)->moveTo(…)->lineTo(…), Path::ellipse(…), Path::rect(…). */
class_alias(MyPHP\UI\Path::class, 'Path');

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

/**
 * Text, as SwiftUI's: $text the key of its translation in the app's catalogs (locales/zh-Hans.json: {"Profile": "个人资料"}),
 * shown as it is where none has it, $args its ICU placeholders (Text('Hello, {name}!', ['name' => $this->name])), its inline
 * Markdown read (**bold**, *italic*, `code`, ~~struck~~); Text(verbatim: $note->title), for what the user wrote, neither.
 * @param array<string, mixed> $args
 */
/**
 * SwiftUI's Text: $text the key of its translation, $args its placeholders; $verbatim shown as it is. An array of Texts
 * is their concatenation, each in its own style, as SwiftUI's Text(a) + Text(b): Text([Text('Error')->bold()->foregroundStyle(Color::red), Text(verbatim: $why)]).
 * @param string|list<D\Text> $text
 */
function Text(string|array $text = '', array $args = [], ?string $verbatim = null): D\Text
{
    if (is_array($text)) { return new D\Text('', false, [], $text); }
    return $verbatim !== null ? new D\Text($verbatim, true) : new D\Text($text, false, $args);
}

/** A button: Button('Save', action: fn() => $this->save()), with a symbol: Button('Copy', systemImage: 'doc.on.doc', action: …), or with a label view: Button(action: …, label: Label('Add', systemImage: 'plus')). */
function Button(string $title = '', ?Closure $action = null, ?ButtonRole $role = null, ?D\View $label = null, ?string $systemImage = null): D\Button
{
    return new D\Button($title, $action, $role, $label, $systemImage);
}

/** A switch bound to $isOn: Toggle('Wi-Fi', isOn: $this->wifi), or a Binding(get:set:) whose set() runs as the user switches it; or of several, Toggle('All', sources: [&$this->mail, &$this->calendar]), mixed while some are on. */
function Toggle(string $title, bool|D\Binding|null &$isOn = null, ?array $sources = null): D\Toggle
{
    if ($sources !== null) { return D\Toggle::of($title, $sources); }
    return new D\Toggle($title, $isOn);
}

/** A field bound to $text: TextField('Name', text: $this->name). */
function TextField(string $title, string &$text, ?string $prompt = null, Axis $axis = Axis::horizontal): D\TextField
{
    return new D\TextField($title, $text, $prompt, false, $axis === Axis::vertical);
}

/** A field for a search, a glass at its left and a button clearing it, as a Mac's: SearchField(text: $this->query). myphp's own, as .searchable is a NavigationStack's. */
function SearchField(string &$text, ?string $prompt = null): D\TextField
{
    return new D\TextField('Search', $text, $prompt, search: true);
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

/**
 * SwiftUI's ForEach (foreach is PHP's): Each($this->todos, id: 'id')(fn(Todo $t) => Text($t->title)). A string or an int is
 * its own id, as id: \.self; lines that repeat are told apart by their place, as id: \.offset: id: fn($line, int $i) => $i.
 * @param iterable<mixed> $items
 */
function Each(iterable $items, string|Closure|null $id = null): D\Each
{
    return new D\Each($items, $id);
}

// ---- collections, navigation ---------------------------------------------------------------------------------

/** SwiftUI's ScrollView: ScrollView()( … ), across with ScrollView(Axis::horizontal). */
function ScrollView(Axis $axes = Axis::vertical): D\ScrollView { return new D\ScrollView($axes); }

/**
 * SwiftUI's List (list is PHP's): of data, ListView($this->notes, id: 'id')(fn(Note $n) => Text($n->title)), only the
 * rows in view built; of views, ListView()( Section(header: 'A')( … ), … ), an inset grouped list. $selection, bound: the id
 * of the row chosen, or, an array, the ids of the rows chosen (Cmd-click, Shift-click, Cmd+A), as SwiftUI's List(selection:)
 * of an ID? or a Set; a NavigationSplitView's sidebar binds the tag chosen. @param array<mixed>|null $items
 */
function ListView(array|int|null $items = null, string|Closure|null $id = null, mixed &$selection = null): D\ListView
{
    return new D\ListView($items, $id, $selection, func_num_args() >= 3); // selection given: it binds it
}

/**
 * SwiftUI's Table: Table($this->rows, id: 'id', selection: $this->chosen)( TableColumn('Name', fn($row) => Text($row->name)), … ),
 * the rows in view built, the columns resized and moved by their headers. sortOrder, bound: the SortOrder of the header
 * clicked, its columns with a value: TableColumn('Name', value: 'name'); the app sorts its rows (onChange(of: $this->sortOrder)).
 * @param array<mixed>|int $rows
 */
function Table(array|int $rows, string|Closure|null $id = null, mixed &$selection = D\Table::UNBOUND, mixed &$sortOrder = D\Table::UNBOUND): D\Table
{
    // a binding not given keeps the default, which no app's value is
    return new D\Table($rows, $id, $selection, $selection !== D\Table::UNBOUND, $sortOrder, $sortOrder !== D\Table::UNBOUND);
}

/** A column of a Table: its title and the view of each row, ->width(min:ideal:max:), ->alignment(); value: a key or fn($row), what it sorts by (and shows, without $content). */
function TableColumn(string $title, ?Closure $content = null, string|Closure|null $value = null): D\TableColumn { return new D\TableColumn($title, $content, $value); }

/** SwiftUI's GroupBox: GroupBox('Files')( … ), its views in a rounded box, its title above. */
function GroupBox(string $title = ''): D\GroupBox { return new D\GroupBox($title); }

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

/** A tab of a TabView, its $value what the TabView's selection holds while it shows; its $image shows on a phone's tab bar and in a sidebar. */
/** A tab of a TabView: Tab('Inbox', systemImage: 'tray', value: 'inbox')( …its views… ), or with an SVG, image: $svg. */
function Tab(string $title = '', ?SVG $image = null, mixed $value = null, ?string $systemImage = null, ?TabRole $role = null): D\Tab { return new D\Tab($title, $image, $value, $systemImage, $role); }

/** How a TabView shows its tabs, as SwiftUI's TabViewStyle. */
enum TabViewStyle { case automatic; case sidebarAdaptable; }
/** A height a sheet comes to rest at, as SwiftUI's PresentationDetent: medium, half the window; large, all of it but its top; a fraction of it; a height. */
final class PresentationDetent
{
    private function __construct(private readonly string $kind, private readonly float $value) {}

    public static function medium(): self { return new self('fraction', 0.5); }
    public static function large(): self { return new self('fraction', 1.0); }
    public static function fraction(float $f): self { return new self('fraction', $f); }
    public static function height(float $points): self { return new self('height', $points); }

    /** @internal its height in points, of a sheet whose largest is $large */
    public function points(float $large): float
    {
        return $this->kind === 'fraction' ? $large * $this->value : $this->value;
    }
}

/** When a phone's tab bar shrinks to the chosen tab as the page scrolls, as SwiftUI's on iOS 26: automatic is never on an iPhone. */
enum TabBarMinimizeBehavior { case automatic; case onScrollDown; case onScrollUp; case never; }
/** What a Tab is, as SwiftUI's TabRole: search, the tab of the app's search, titled Search and shown by a magnifying glass unless it says otherwise, in the tab bar with the others (iOS 27's); prominent, a tab apart at the end of a phone's tab bar, a circle of glass (iOS 27's, what search was in iOS 26). */
enum TabRole { case search; case prominent; }

/** SwiftUI's NavigationBarItem.TitleDisplayMode. */
enum TitleDisplayMode { case automatic; case inline; case large; }

/** SwiftUI's NavigationPath: a Router, of paths: append('/notes/42'), removeLast(), count, isEmpty. */
class_alias(MyPHP\UI\Router::class, 'NavigationPath');

// ---- controls --------------------------------------------------------------------------------------------------

/** One of the tagged choices of its content, bound to $selection: Picker('Flavor', selection: $this->flavor)( Text('Vanilla')->tag('vanilla'), … ). */
function Picker(string $title, mixed &$selection): D\Picker { return new D\Picker($title, $selection); }

/** A slider bound to $value, between the ends of $in: Slider(value: $this->volume, in: [0, 100], step: 5). */
function Slider(float|int|D\Binding &$value, array $in = [0.0, 1.0], ?float $step = null, string $title = '', ?Closure $onEditingChanged = null): D\Slider { return new D\Slider($value, $in, $step, $title, $onEditingChanged); }

/** Buttons stepping $value by $step between the ends of $in: Stepper('Quantity', value: $this->qty, in: [1, 10]). */
function Stepper(string $title, int|float &$value, array $in = [PHP_INT_MIN, PHP_INT_MAX], float $step = 1): D\Stepper { return new D\Stepper($title, $value, $in, $step); }

/** A date, a time or both, bound to $selection: DatePicker('Due', selection: $this->due, displayedComponents: [DatePickerComponents::date]). */
function DatePicker(string $title, DateTimeImmutable &$selection, array $displayedComponents = [DatePickerComponents::date, DatePickerComponents::hourAndMinute]): D\DatePicker
{
    return new D\DatePicker($title, $selection, $displayedComponents);
}

/** A color well bound to $selection, 0xRRGGBB: ColorPicker('Tint', selection: $this->tint); with supportsOpacity, its $opacity too. */
function ColorPicker(string $title, int &$selection, bool $supportsOpacity = false, ?float &$opacity = null): D\ColorPicker
{
    return new D\ColorPicker($title, $selection, $supportsOpacity, $opacity);
}

/** A spinner, ProgressView(); a bar, ProgressView('Downloading', value: $done, total: $size). */
function ProgressView(string $title = '', ?float $value = null, float $total = 1.0): D\ProgressView { return new D\ProgressView($title, $value, $total); }

/** A level between the ends of $in: Gauge(value: $this->cpu, in: [0, 100], title: 'CPU'). */
function Gauge(float $value, array $in = [0.0, 1.0], string $title = ''): D\Gauge { return new D\Gauge($value, $in, $title); }

/** A button opening a menu of its content: Menu('Sort')( Button('Name', action: …), Divider(), Toggle('Reverse', isOn: …) ). */
function Menu(string $title): D\Menu { return new D\Menu($title); }

/**
 * Text editing $text over lines, taking the room it is given, as SwiftUI's TextEditor; or a large document, a
 * MyPHP\UI\TextBuffer indexed by its lines, edited in place: TextEditor(buffer: $this->document).
 */
function TextEditor(?string &$text = null, ?MyPHP\UI\TextBuffer $buffer = null, ?MyPHP\UI\RichText\Document $document = null, ?MyPHP\UI\RichText\EditorOptions $options = null): D\View
{
    if ($document !== null) { return new D\Representable(static fn(MyPHP\UI\Context $c) => MyPHP\UI\RichText\RichText::editor($c, $document, $options), true); } // $options: its placeholder, ⌘K's link command, its look // rich text, as SwiftUI's TextEditor of an AttributedString: its toolbar formats through $document
    if ($buffer !== null) { return new D\Representable(static fn(MyPHP\UI\Context $c) => $c->textAreaBuffer($buffer, '', 0, 0), true); }
    $text ??= '';
    return new D\TextField('', $text, null, false, false, true);
}

/** SwiftUI's Link, named WebLink (PHP has a link() of its own): WebLink('Docs', destination: 'https://…'). */
function WebLink(string $title, string $destination): D\WebLink { return new D\WebLink($title, $destination); }

/** Its content shown while expanded, bound to $isExpanded or kept by itself: DisclosureGroup('Advanced')( … ). */
function DisclosureGroup(string $title, ?bool &$isExpanded = null): D\DisclosureGroup { return new D\DisclosureGroup($title, $isExpanded); }

/**
 * A sidebar beside the detail, as SwiftUI's NavigationSplitView: the sidebar a ListView(selection:) of tagged Labels
 * and Sections, the detail the view of what it chose: NavigationSplitView(sidebar: ListView(selection: $this->page)(
 * Label('Inbox', systemImage: 'tray')->tag('inbox'), … ), detail: Detail($this->page)).
 */
function NavigationSplitView(D\ListView $sidebar, D\View|array $detail, mixed &$columnVisibility = D\NavigationSplitView::UNBOUND): D\NavigationSplitView
{
    return new D\NavigationSplitView($sidebar, $detail, $columnVisibility, $columnVisibility !== D\NavigationSplitView::UNBOUND); // columnVisibility: the sidebar shown or hidden, and its button
}

/** Which columns of a NavigationSplitView show, as SwiftUI's: all, or the detail only. */
enum NavigationSplitViewVisibility { case automatic; case all; case detailOnly; }

// ---- other views of myphp's, among the declarative ones --------------------------------------------------------

/** SwiftUI's NSViewRepresentable: the node $make builds into the context, a plugin's view, among the declarative views. */
function Representable(Closure $make, bool $fills = false): D\Representable { return new D\Representable($make, $fills); }

/** A game of the Game plugin, its scene, taking the room it is given, as SwiftUI's SpriteView(scene:). */
function SpriteView(MyPHP\Plugins\Game\Game $scene): D\Representable { return new D\Representable(static fn(MyPHP\UI\Context $c) => MyPHP\Plugins\Game\Game::view($c, $scene), true); }

/** A film and its controls, taking the room it is given, as SwiftUI's VideoPlayer(player:). */
function VideoPlayer(MyPHP\UI\MediaPlayerState $player, ?MyPHP\UI\MediaPlayerOptions $options = null): D\Representable
{
    return new D\Representable(static fn(MyPHP\UI\Context $c) => $c->mediaPlayer($player, $options), true);
}

/** A terminal of the Terminal plugin, taking the room it is given. */
function TerminalView(MyPHP\Plugins\Terminal\Terminal $terminal): D\Representable { return new D\Representable(static fn(MyPHP\UI\Context $c) => MyPHP\Plugins\Terminal\Terminal::view($c, $terminal), true); }

/** A web view's page (MyPHP\WebView, Window::newWebView) among the views, taking the room it is given, as SwiftUI's WebView(page): what shows over it (menus, sheets, overlays) shows over the page. */
function WebView(MyPHP\WebView $page): D\Representable { return new D\Representable(static fn(MyPHP\UI\Context $c) => $c->webView($page), true); }

// ---- myphp's own controls, which SwiftUI has none of (AppKit's NSComboBox, NSTokenField and the like) -------------

/** A field with a menu of $items to choose from, or any text typed, as AppKit's NSComboBox: ComboBox('Fruit', text: $this->fruit, items: FRUITS). */
function ComboBox(string $title, string &$text, array $items, string $prompt = ''): D\Control
{
    return new D\Control($title, static function (MyPHP\UI\Context $c) use (&$text, $items, $prompt): Node { return $c->combobox($text, $items, 0, $prompt); }, true);
}

/** Tokens typed into a field, each a chip, completed from $suggestions, as AppKit's NSTokenField: TokenField('Tags', tokens: $this->tags, suggestions: TAGS). */
function TokenField(string $title, array &$tokens, array $suggestions = [], string $prompt = ''): D\Control
{
    return new D\Control($title, static function (MyPHP\UI\Context $c) use (&$tokens, $suggestions, $prompt): Node { return $c->tokenField($tokens, $suggestions, 0, $prompt); }, true);
}

/** Stars from 1 to $maximum, $value of them filled, a click or the arrows choosing: Rating('Rating', value: $this->stars). */
function Rating(string $title, int &$value, int $maximum = 5): D\Control
{
    return new D\Control($title, static function (MyPHP\UI\Context $c) use (&$value, $maximum): Node { return $c->rating($value, $maximum); });
}

/** A slider of two knobs, $lower and $upper, between the ends of $in, by $step when given: RangeSlider(lower: $this->low, upper: $this->high, in: [0, 100], step: 5). */
function RangeSlider(float|int &$lower, float|int &$upper, array $in = [0.0, 1.0], ?float $step = null, string $title = ''): D\Control
{
    return new D\Control($title, static function (MyPHP\UI\Context $c) use (&$lower, &$upper, $in, $step): Node {
        [$lo, $hi] = [(float)$lower, (float)$upper];
        $n = $c->rangeSlider($lo, $hi, (float)$in[0], (float)$in[1], $step ?? 0.0);
        [$lower, $upper] = is_int($lower) ? [(int)round($lo), (int)round($hi)] : [$lo, $hi];
        return $n;
    }, true);
}

/** A path of $path, Finder's path bar: the items but the last are links, a click setting $selection to its index: Breadcrumbs(['Users', 'developer'], selection: $this->at). */
function Breadcrumbs(array $path, int &$selection): D\Control
{
    return new D\Control('', static function (MyPHP\UI\Context $c) use ($path, &$selection): Node { return $c->breadcrumbs($path, $selection); });
}

/** A circle of a person's $image, or their initials on a color of their name: Avatar('Ada Lovelace'). */
function Avatar(string $name, ?UIImage $image = null): D\Control
{
    return new D\Control('', static fn(MyPHP\UI\Context $c) => $c->avatar($name, $image));
}

// ---- Swift Charts ------------------------------------------------------------------------------------------------

/**
 * A chart of the marks its content makes, as Swift Charts' Chart: Chart($items)(fn($item) => BarMark(…)), or
 * Chart()(LineMark(…), RuleMark(…), …). It takes the room it is given: ->frame(height: 200).
 * @param iterable<mixed>|null $data
 */
function Chart(?iterable $data = null): D\Chart { return new D\Chart($data); }

/** A bar from 0 (or $yStart) to $y at $x, stacked on the bars of its category before it. */
function BarMark(mixed $x = null, mixed $y = null, mixed $yStart = null, mixed $yEnd = null, ?float $width = null): D\ChartMark
{
    return new D\ChartMark(D\ChartMark::BAR, D\PlottableValue::of($x), D\PlottableValue::of($y ?? $yEnd), D\PlottableValue::of($yStart), D\PlottableValue::of($yEnd), width: $width);
}

/** A point of a line through the marks of its series ($series, or its foregroundStyle(by:)), in the order of x. */
function LineMark(mixed $x, mixed $y, mixed $series = null): D\ChartMark
{
    return new D\ChartMark(D\ChartMark::LINE, D\PlottableValue::of($x), D\PlottableValue::of($y), series: D\PlottableValue::of($series));
}

/** A point of an area under the marks of its series, stacked on the series before it as $stacking says (standard by default). */
function AreaMark(mixed $x, mixed $y = null, mixed $yStart = null, mixed $yEnd = null, MarkStackingMethod $stacking = MarkStackingMethod::standard): D\ChartMark
{
    return new D\ChartMark(D\ChartMark::AREA, D\PlottableValue::of($x), D\PlottableValue::of($y ?? $yEnd), D\PlottableValue::of($yStart), D\PlottableValue::of($yEnd), stacking: $stacking);
}

/**
 * A line through many points at once, as Swift Charts' vectorized LinePlot: $x and $y their values, in order of x, along
 * linear scales; it paints the extremes of each pixel column, so tens of thousands of points a frame stay quick.
 * @param list<float> $x @param list<float> $y
 */
function LinePlot(array $x, array $y): D\ChartMark
{
    $m = new D\ChartMark(D\ChartMark::PLOT);
    [$m->xValues, $m->yValues] = [$x, $y];
    return $m;
}

/** A dot at $x, $y: of a scatter plot, or the point a selection is on. */
function PointMark(mixed $x, mixed $y): D\ChartMark { return new D\ChartMark(D\ChartMark::POINT, D\PlottableValue::of($x), D\PlottableValue::of($y)); }

/** A line across the plot: at $x, from top to bottom; or at $y, from side to side. */
function RuleMark(mixed $x = null, mixed $y = null): D\ChartMark { return new D\ChartMark(D\ChartMark::RULE, D\PlottableValue::of($x), D\PlottableValue::of($y)); }

/** A sector of a pie, as large as its $angle's share of all; a donut's with an $innerRadius; $angularInset points between sectors. */
function SectorMark(mixed $angle, ?D\MarkDimension $innerRadius = null, ?D\MarkDimension $outerRadius = null, float $angularInset = 0): D\ChartMark
{
    return new D\ChartMark(D\ChartMark::SECTOR, angle: D\PlottableValue::of($angle), innerRadius: $innerRadius, outerRadius: $outerRadius, angularInset: $angularInset);
}

/** How the areas of several series stack, as Swift Charts' MarkStackingMethod. */
enum MarkStackingMethod { case standard; case normalized; case center; case unstacked; }

/** How a line goes from point to point, as Swift Charts' InterpolationMethod. */
enum InterpolationMethod { case linear; case monotone; case catmullRom; case cardinal; case stepStart; case stepCenter; case stepEnd; }

/** Where a mark's annotation goes: above it by default. */
enum AnnotationPosition { case automatic; case top; case bottom; case leading; case trailing; }

/** The side of the plot an axis's labels are on. */
enum AxisMarkPosition { case automatic; case leading; case trailing; }

/** Whether something shows, as SwiftUI's Visibility: a chart's legend. */
enum Visibility { case automatic; case visible; case hidden; }

/** A drag that moves the window, of a view standing in for its title bar, as SwiftUI's WindowDragGesture: ->gesture(WindowDragGesture()). */
final class WindowDragGesture {}
function WindowDragGesture(): WindowDragGesture { return new WindowDragGesture(); }

/** The view its content makes of the moment, built again every frame while it shows, as SwiftUI's TimelineView(.animation): TimelineView()(fn(TimelineViewContext $context) => …). */
/** @param list<int>|Closure(): list<int>|null $explicit frame times in nanoseconds it builds at only, as SwiftUI's .explicit schedule */
function TimelineView(bool $paused = false, array|Closure|null $explicit = null): D\TimelineView { return new D\TimelineView($paused, $explicit); }

// ---- animation ----------------------------------------------------------------------------------------------------

/** Runs $body, the views animating what it changes with $animation (Animation::default() when not given; null: at once), as SwiftUI's withAnimation. */
function withAnimation(Closure|MyPHP\UI\Animation|null $animation, ?Closure $body = null): mixed
{
    if ($animation instanceof Closure) { [$body, $animation] = [$animation, MyPHP\UI\Animation::default()]; }
    return D\Transaction::with($animation, $body ?? throw new InvalidArgumentException('withAnimation takes what changes: withAnimation(Animation::snappy(), fn() => $this->open = true)'));
}

/** A drag of the pointer on the view, of $minimumDistance points at least, as SwiftUI's DragGesture. */
function DragGesture(float $minimumDistance = 10): D\DragGesture { return new D\DragGesture($minimumDistance); }

/**
 * The view $content makes of the value $keyframes give it over time, as SwiftUI's KeyframeAnimator: played as $trigger
 * changes, or over and over while $repeating. $keyframes: KeyframeTracks of $initialValue's keys, or Keyframes of a number.
 * @param list<D\KeyframeTrack|D\Keyframe>|Closure $keyframes
 */
function KeyframeAnimator(array|object|float|int $initialValue, Closure $content, array|Closure $keyframes, mixed $trigger = D\KeyframeAnimator::UNSET, bool $repeating = false): D\KeyframeAnimator
{
    return new D\KeyframeAnimator($initialValue, $trigger, $repeating, $content, $keyframes);
}

/** The keyframes of the value at $property of a KeyframeAnimator's value: KeyframeTrack('scale')( LinearKeyframe(1.2, duration: 0.2), … ). */
function KeyframeTrack(string $property = ''): D\KeyframeTrack { return new D\KeyframeTrack($property); }

/** To $to over $duration seconds at an even pace, or along $timingCurve. */
function LinearKeyframe(float $to, float $duration, UnitCurve $timingCurve = UnitCurve::linear): D\Keyframe { return new D\Keyframe(D\Keyframe::LINEAR, $to, $duration, $timingCurve->ease()); }

/** To $to over $duration seconds along a smooth curve through the keyframes on either side. */
function CubicKeyframe(float $to, float $duration): D\Keyframe { return new D\Keyframe(D\Keyframe::CUBIC, $to, $duration); }

/** To $to on a spring, for $duration seconds. */
function SpringKeyframe(float $to, float $duration = 0.5, ?MyPHP\UI\Animation $spring = null): D\Keyframe { return new D\Keyframe(D\Keyframe::SPRING, $to, $duration, null, $spring?->spring); }

/** To $to at once. */
function MoveKeyframe(float $to): D\Keyframe { return new D\Keyframe(D\Keyframe::MOVE, $to, 0.0); }

/** Which way the views lay out, as SwiftUI's LayoutDirection: right to left as Arabic and Hebrew read. */
enum LayoutDirection { case leftToRight; case rightToLeft; }

/** Where a gesture's points are: in the view's (local) or the window's (global), as SwiftUI's CoordinateSpace. */
enum CoordinateSpace { case local; case global; }

/** A curve from 0 to 1 a keyframe goes along, as SwiftUI's UnitCurve. */
enum UnitCurve
{
    case linear; case easeIn; case easeOut; case easeInOut;

    /** @internal */
    public function ease(): ?Closure
    {
        return match ($this) {
            self::linear => null,
            self::easeIn => MyPHP\UI\Easing::cubicBezier(0.42, 0, 1, 1),
            self::easeOut => MyPHP\UI\Easing::cubicBezier(0, 0, 0.58, 1),
            self::easeInOut => MyPHP\UI\Easing::cubicBezier(0.42, 0, 0.58, 1),
        };
    }
}

/** What $renderer draws in a GraphicsContext of the size it is given, as SwiftUI's Canvas; it takes the room. */
function Canvas(Closure $renderer): D\Canvas { return new D\Canvas($renderer); }

/** GeometryReader, as SwiftUI's: the view $content makes of the room it is given (GeometryProxy), which it takes. */
function GeometryReader(Closure $content): D\GeometryReader { return new D\GeometryReader($content); }

/** HSplitView, as SwiftUI's on macOS: its views side by side, dividers the user drags between them; a pane starts frame(idealWidth:) wide. */
function HSplitView(): D\HSplitView { return new D\HSplitView(); }

/** ScrollViewReader, as SwiftUI's: the view $content makes, whose ScrollViewProxy scrolls the ListView inside: $proxy->scrollTo($id). */
function ScrollViewReader(Closure $content): D\ScrollViewReader { return new D\ScrollViewReader($content); }

/** Cells in as many columns as fit, row by row: LazyVGrid(columns: [GridItem::adaptive(minimum: 132)], spacing: 12)( … ). @param list<D\GridItem> $columns */
function LazyVGrid(array $columns, float $spacing = 8, mixed &$selection = D\LazyVGrid::UNBOUND): D\LazyVGrid
{
    return new D\LazyVGrid($columns, $spacing, $selection, $selection !== D\LazyVGrid::UNBOUND); // selection: the id chosen, or an array of them, as a List's
}

/** Its views one under another, only those in view built, in a ScrollView: ScrollView()( LazyVStack(spacing: 16)( Each(…) ) ). */
function LazyVStack(HorizontalAlignment $alignment = HorizontalAlignment::center, float $spacing = 8): D\LazyVStack { return new D\LazyVStack($alignment, $spacing); }

/** A point of a view, as SwiftUI's UnitPoint: where a ScrollView starts (defaultScrollAnchor), where scrollTo() puts a row. */
enum UnitPoint { case top; case center; case bottom; }

/** MyPHP's code editor of $state (MyPHP\UI\Editor\State): its buffer edited, highlighted, completed and diagnosed as $state says; it takes the room. */
function CodeEditor(MyPHP\UI\Editor\State $state): D\Representable { return new D\Representable(static fn(MyPHP\UI\Context $c) => $state->view($c), true); }

/** The diff of $diff (MyPHP\UI\Editor\DiffState): split or unified, as it says, editing its buffer; it takes the room. */
function DiffView(MyPHP\UI\Editor\DiffState $diff): D\Representable { return new D\Representable(static fn(MyPHP\UI\Context $c) => $diff->view($c), true); }

/**
 * Markdown, its blocks and inline styles (as MarkdownUI's Markdown view): $streaming while it is still coming in, its
 * last block kept open; $options its theme, links and the pace it reveals at. @param ?MyPHP\UI\Markdown\Options $options
 */
function Markdown(string $text, ?MyPHP\UI\Markdown\Options $options = null, bool $streaming = false): D\Representable
{
    return new D\Representable(static fn(MyPHP\UI\Context $c) => $streaming ? MyPHP\UI\Markdown\Markdown::stream($c, $text, $options) : MyPHP\UI\Markdown\Markdown::view($c, $text, $options));
}
