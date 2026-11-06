<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use DateTimeImmutable;
use MyPHP\UI\{Context, Node};

/**
 * DatePicker, as SwiftUI's: $selection, bound, its date, its time or both
 * ($displayedComponents); a field that opens a calendar (automatic,
 * compact, field), or the calendar itself (graphical), as
 * datePickerStyle() says.
 */
final class DatePicker extends View
{
    private DateTimeImmutable $selection;

    /** @param list<\DatePickerComponents> $displayedComponents */
    public function __construct(public readonly string $title, DateTimeImmutable &$selection, public readonly array $displayedComponents = [\DatePickerComponents::date, \DatePickerComponents::hourAndMinute])
    {
        $this->selection = &$selection;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $date = in_array(\DatePickerComponents::date, $this->displayedComponents, true);
        $time = in_array(\DatePickerComponents::hourAndMinute, $this->displayedComponents, true);
        $graphical = ($env->datePickerStyle ?? \DatePickerStyle::automatic) === \DatePickerStyle::graphical;
        return LabeledContent::control($c, $env, $graphical ? '' : $this->title, function () use ($c, $env, $date, $time, $graphical): Node {
            $n = $graphical ? $c->column(0, 8) : $c->row(0, 8)->alignItems(Node::CENTER);
              if ($date) { $graphical ? $c->calendar($this->selection) : $c->dateInput($this->selection); }
              if ($time) { $c->timeInput($this->selection); }
            $c->end();
            $n->label(Localized::string($this->title, $env));
            if ($env->disabled) { $n->disabled(); }
            return $n;
        });
    }
}
