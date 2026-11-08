<?php
declare(strict_types=1);

if (!defined('STDIN')) { define('STDIN', fopen('php://stdin', 'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require_once __DIR__ . '/src/Native/LocalePolyfill.php';

use MyPHP\{App, Clipboard, Dialog, MessageOptions, Window, WindowOptions};
use MyPHP\UI\Content;

spl_autoload_register(function ($c) {
    if (str_starts_with($c, 'MyPHP\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($c, 6)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

/** The demo's state, which the view shows and changes. */
final class Demo
{
    public string $name = 'Ada';
    public string $city = '北京';
    public string $note = '';
    public bool $subscribe = false;
    public string $greeting = '';
    public int $submits = 0;

    public function submit(): void
    {
        $this->submits++;
        $this->greeting = "Hello, {$this->name} from {$this->city} (#{$this->submits})";
    }

    public function clear(): void
    {
        [$this->name, $this->city, $this->note, $this->greeting] = ['', '', '', ''];
    }

    public function ask(): void
    {
        $res = Dialog::message(new MessageOptions(title: '系统提示', message: '这是 Android 原生 AlertDialog 对话框！', buttons: ['确定', '取消']));
        $this->greeting = "Dialog result: button #{$res->response}";
    }
}

$s = new Demo();

App::setName('Form');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Form', width: 360, height: 640, content: Content::view(fn() => NavigationStack()(
        Form()(
            Section('Profile')(
                TextField('Name', text: $s->name)->onSubmit(fn() => $s->submit()),
                TextField('City', text: $s->city, prompt: 'City（试试中文输入法）')->onSubmit(fn() => $s->submit()),
                TextField('Note', text: $s->note, prompt: 'A few lines of note', axis: Axis::vertical),
                Toggle('Subscribe', isOn: $s->subscribe),
            ),
            Section('Tags')(
                ScrollView(Axis::horizontal)(HStack(spacing: 8)(
                    Each(range(0, 19))(fn(int $i) => VStack(spacing: 2)(
                        Text(verbatim: "Tag $i"),
                        Text(verbatim: sprintf('#%06x', ($i * 2654435761) & 0xffffff))->font(Font::caption)->foregroundStyle(Color::secondary),
                    )->padding(6)),
                )),
            ),
            Section()(
                Button('Submit', action: fn() => $s->submit()),
                Button('Clear', action: fn() => $s->clear()),
                Button('Copy Text', action: function () use ($s) { Clipboard::writeText('剪贴板测试成功！Ada from 北京'); $s->greeting = 'Copied to clipboard!'; }),
                Button('Paste Text', action: function () use ($s) { $clip = Clipboard::readText(); $s->greeting = 'Pasted: ' . ($clip !== '' ? $clip : '(empty)'); }),
                Button('Show Dialog', action: fn() => $s->ask()),
                WebLink('Open myphp on GitHub', destination: 'https://github.com/owner888/myphp'),
            ),
            $s->greeting === '' ? null : Section()(Text(verbatim: $s->greeting)),
        )->navigationTitle('Profile'),
    ))));
});

App::run();
