<?php
// The app's view, $this the Mobile main.php makes (Content::file): three tabs, each a NavigationStack; the list's
// rows go to pages/row.php. Saved, each file hot reloads.
declare(strict_types=1);

use MyPHP\Haptics;

/** The list: 200 000 rows, only those in view built, each swiped for Flag and Delete, tapped for its page. */
$home = fn() => ListView(Mobile::ROWS)(fn(int $i) =>
    NavigationLink(value: "/row/$i", label: HStack(spacing: 12)(
        Text((string)($i + 1))->foregroundStyle(Color::secondary)->frame(width: 64, alignment: Alignment::leading),
        Text("第 $i 行 · " . md5((string)$i))->lineLimit(1),
    ))->swipeActions(
        Button('标记', action: fn() => $this->swiped($i, '标记')),
        Button('删除', role: ButtonRole::destructive, action: fn() => $this->swiped($i, '删除')),
    )
)->navigationTitle(number_format(Mobile::ROWS) . ' 行');

/** The form: a field, the system's pickers, an HTTPS request. */
$form = fn() => Form()(
    Section(header: '姓名', footer: '点输入框弹键盘，中文输入法组字，点空白收起')(
        TextField('姓名', text: $this->name, prompt: '请输入姓名'),
    ),
    Section(header: '选取')(
        Button('选图片', action: fn() => $this->pick(['jpg', 'jpeg', 'png', 'heic', 'webp'], true)),
        Button('选文件', action: fn() => $this->pick(['pdf', 'xlsx', 'docx', 'txt', 'csv'], false)),
        $this->photo !== null ? Image($this->photo)->resizable()->frame(height: 240)->cornerRadius(8) : null,
        $this->photoPath !== '' ? LabeledContent(basename($this->photoPath), number_format((int)filesize($this->photoPath)) . ' B') : null,
        Each($this->files)(fn(string $f) => LabeledContent(basename($f), number_format((int)filesize($f)) . ' B')),
    ),
    Section(header: '网络', footer: $this->http)(
        Button('HTTPS GET example.com', action: fn() => $this->fetch()),
    ),
)->navigationTitle('表单');

/** Settings as iOS's: a large title, pull to refresh, inset grouped sections. */
$me = fn() => ListView()(
    Text("下拉刷新过 {$this->refreshes} 次")->font(Font::footnote)->foregroundStyle(Color::secondary),
    Section(header: '弹层', footer: $this->lastAction)(
        Button('底部弹层 sheet', action: fn() => $this->sheet = true),
        Button('操作菜单 confirmationDialog', action: fn() => $this->actions = true),
        Button('确认弹窗 alert', action: fn() => $this->alert = true),
    ),
    Section(header: '触感 Haptics', footer: 'iPhone 的 Taptic Engine、Android 的振动马达；Mac 的触控板要有手指放着。')(
        Each(['light', 'medium', 'heavy', 'soft', 'rigid'])(fn(string $style) => Button("impact $style", action: fn() => Haptics::impact($style))),
        Button('selection', action: fn() => Haptics::selection()),
        Button('success', action: fn() => Haptics::success()),
        Button('warning', action: fn() => Haptics::warning()),
        Button('error', action: fn() => Haptics::error()),
    ),
    Section(header: '文字层级 Font', footer: '手机上是 iOS 的尺寸（body 17），桌面是 macOS 的（body 13），都随系统文字大小缩放。')(
        Each(Font::cases(), id: fn(Font $f) => $f->name)(fn(Font $f) => Text($f->name)->font($f)),
    ),
)
    ->navigationTitle('我的')
    ->navigationBarTitleDisplayMode(TitleDisplayMode::large)
    ->refreshable(fn() => $this->refresh());

return fn() => TabView(selection: $this->tab)(
    Tab('列表', image: $this->homeIcon, value: 0)(
        NavigationStack(path: $this->homePath)($home())
            ->navigationDestination(for: '/row/{i}', destination: __DIR__ . '/pages/row.php'),
    ),
    Tab('表单', image: $this->formIcon, value: 1)(
        NavigationStack(path: $this->formPath)($form()),
    ),
    Tab('我的', image: $this->meIcon, value: 2)(
        NavigationStack(path: $this->mePath)($me()),
    ),
)
    ->sheet(isPresented: $this->sheet, content: fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
        Text('底部弹层')->font(Font::headline),
        Text('点空白处、按返回键或 Escape 关闭。')->foregroundStyle(Color::secondary),
        Button('关闭', action: fn() => $this->sheet = false)->buttonStyle(ButtonStyle::borderedProminent),
    ))
    ->confirmationDialog('这一行', isPresented: $this->actions, actions: [
        Button('分享', action: fn() => $this->lastAction = 'confirmationDialog: 分享'),
        Button('重命名', action: fn() => $this->lastAction = 'confirmationDialog: 重命名'),
        Button('删除', role: ButtonRole::destructive, action: fn() => $this->lastAction = 'confirmationDialog: 删除'),
    ])
    ->alert('删除这一行？', isPresented: $this->alert, actions: [
        Button('取消', role: ButtonRole::cancel, action: fn() => $this->lastAction = 'alert: 取消'),
        Button('删除', role: ButtonRole::destructive, action: fn() => $this->lastAction = 'alert: 删除'),
    ], message: '删除后不能恢复。');
