<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

/** The series' colors, by their names. */
$styles = fn(array $names) => array_combine($names, array_map(fn(int $i) => Color::rgb(COLORS[$i]), array_keys($names)));

/** A card: a titled panel. */
$card = fn(string $title, View ...$body) => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(Text($title)->font(Font::headline), ...$body)
    ->padding(16)->frame(maxWidth: INF, alignment: Alignment::topLeading)->background(Color::gray->opacity(0.08))->cornerRadius(10)->border(Color::separator);

// Bars of the months; the pointer chooses one, which the others dim for and which shows its value.
$sales = fn() => $card('Sales',
    HStack()(
        Text(verbatim: $this->month !== null ? sprintf('%s: $%.1fk', $this->month, $this->sales[array_search($this->month, MONTHS, true)]) : 'Point at a bar to see its month.')->foregroundStyle(Color::secondary),
        Spacer(),
        Button('Shuffle', action: function () { foreach ($this->sales as $i => $_) { $this->sales[$i] = 10 + 110 * mt_rand() / mt_getrandmax(); } }),
    ),
    Chart(array_map(null, MONTHS, $this->sales))(fn(array $s) => BarMark(x: PlottableValue::value('Month', $s[0]), y: PlottableValue::value('Sales', $s[1]))
        ->cornerRadius(3)->opacity($this->month !== null && $this->month !== $s[0] ? 0.35 : 1.0)
        ->annotation(fn() => $this->month === $s[0] ? Text(sprintf('%.0f', $s[1]))->font(Font::caption)->bold() : null))
        ->chartXSelection(value: $this->month)
        ->chartYAxis(new AxisMarks(format: fn(float $v) => $v == 0 ? '$0' : sprintf('$%.0fk', $v)))
        ->frame(height: 200),
);

// The last minute, a sample a second: the pointer, or the arrows once it has the focus, choose a second, which a rule and a point show.
$live = function () use ($card) {
    $i = $this->at === null ? null : count($this->samples) - 1 - ($this->now->getTimestamp() - $this->at->getTimestamp()); // the sample of the second chosen
    if ($i !== null && !isset($this->samples[$i])) { $i = null; }
    return $card('Live data',
        Text(verbatim: $i !== null ? sprintf('%s: %.0f%%', $this->at->format('H:i:s'), 100 * $this->samples[$i]) : 'Point at the line, or focus it and use the arrows.')->foregroundStyle(Color::secondary),
        Chart()(array_map(fn(int $k) => [
            AreaMark(x: PlottableValue::value('Time', $this->timeOf($k)), y: PlottableValue::value('Load', $this->samples[$k]), stacking: MarkStackingMethod::unstacked)
                ->foregroundStyle(Color::accentColor->opacity(0.12))->interpolationMethod(InterpolationMethod::monotone),
            LineMark(x: PlottableValue::value('Time', $this->timeOf($k)), y: PlottableValue::value('Load', $this->samples[$k]))
                ->foregroundStyle(Color::accentColor)->interpolationMethod(InterpolationMethod::monotone),
        ], array_keys($this->samples)), $i !== null ? [
            RuleMark(x: $this->at),
            PointMark(x: $this->at, y: $this->samples[$i])->foregroundStyle(Color::accentColor)->symbolSize(100),
        ] : null)
            ->chartXSelection(value: $this->at)
            ->chartYScale(domain: [0, 1])
            ->chartYAxis(new AxisMarks(format: fn(float $v) => sprintf('%.0f%%', 100 * $v)))
            ->frame(height: 200),
    );
};

// Visits of each source, a week a point, stacked as values or as shares of each week's.
$stacked = fn() => $card('Visits by source',
    Picker('Stacking', selection: $this->stacking)(Text('Values')->tag(0), Text('Shares')->tag(1))->pickerStyle(PickerStyle::segmented)->labelsHidden(),
    Chart()(...array_merge(...array_map(fn(int $s) => array_map(fn(int $w) => AreaMark(
        x: PlottableValue::value('Week', $w + 1), y: PlottableValue::value('Visits', VISITS[$s][$w]),
        stacking: $this->stacking === 1 ? MarkStackingMethod::normalized : MarkStackingMethod::standard,
    )->foregroundStyle(by: PlottableValue::value('Source', SOURCES[$s]))->interpolationMethod(InterpolationMethod::monotone)->opacity(0.8), array_keys(VISITS[$s])), array_keys(SOURCES))))
        ->chartForegroundStyleScale($styles(SOURCES))
        ->chartXScale(domain: [1, count(VISITS[0])])
        ->chartXAxis(new AxisMarks(desiredCount: 6, format: fn(float $v) => sprintf('W%.0f', $v)))
        ->frame(height: 200),
);

// Cars of three classes, by weight and fuel use.
$scatter = fn() => $card('Weight and fuel use',
    Chart()(...array_merge(...array_map(fn(int $k) => array_map(fn(array $car) => PointMark(x: PlottableValue::value('Weight', $car[0]), y: PlottableValue::value('Fuel use', $car[1]))
        ->foregroundStyle(by: PlottableValue::value('Class', ['Small', 'Midsize', 'Large'][$k]))->opacity(0.8), $this->scatter[$k]), array_keys($this->scatter))))
        ->chartForegroundStyleScale($styles(['Small', 'Midsize', 'Large']))
        ->chartXAxis(new AxisMarks(desiredCount: 4, format: fn(float $v) => sprintf('%.1f t', $v / 1000)))
        ->chartYAxis(new AxisMarks(format: fn(float $v) => sprintf('%.0f L', $v)))
        ->frame(height: 200),
);

// A donut of the platforms' shares; the pointer chooses one, which grows and shows its share in the middle.
$donut = function () use ($card, $styles) {
    $chosen = $this->platformAt($this->angle);
    return $card('Users by platform', HStack(spacing: 20)(
        ZStack()(
            Chart(array_keys(PLATFORMS))(fn(int $i) => SectorMark(angle: PlottableValue::value('Share', SHARES[$i]), innerRadius: MarkDimension::ratio(0.62),
                outerRadius: MarkDimension::ratio($chosen === $i ? 1.0 : 0.95), angularInset: 1.5)->foregroundStyle(by: PlottableValue::value('Platform', PLATFORMS[$i])))
                ->chartForegroundStyleScale($styles(PLATFORMS))->chartLegend(Visibility::hidden)->chartAngleSelection(value: $this->angle)
                ->accessibilityLabel('Users by platform'),
            VStack(spacing: 0)(
                Text(verbatim: $chosen === null ? '4.2M' : sprintf('%.0f%%', SHARES[$chosen]))->font(Font::system(size: 24, weight: Weight::bold)),
                Text(verbatim: $chosen === null ? 'users' : PLATFORMS[$chosen])->font(Font::caption)->foregroundStyle(Color::secondary),
            )->allowsHitTesting(false),
        )->frame(width: 140, height: 140), // with the list, within the narrowest card's 288 points
        VStack(alignment: HorizontalAlignment::leading, spacing: 8)(Each(array_keys(PLATFORMS))(fn(int $i) => HStack(spacing: 8)(
            RoundedRectangle(cornerRadius: 3)->fill(Color::rgb(COLORS[$i]))->frame(width: 10, height: 10),
            Text(verbatim: PLATFORMS[$i]),
            Spacer(minLength: 8),
            Text(verbatim: sprintf('%.0f%%', SHARES[$i]))->foregroundStyle(Color::secondary),
        )))->frame(width: 120),
    ));
};

/** The cards, a page of charts, as many side by side as fit. */
return fn() => ScrollView()(VStack(alignment: HorizontalAlignment::leading, spacing: 16)(
    Text('Swift Charts\' marks — BarMark, LineMark, AreaMark, PointMark, RuleMark, SectorMark — laid out on the scales the chart picks from their values.')->foregroundStyle(Color::secondary),
    LazyVGrid(columns: [GridItem::adaptive(minimum: 320)], spacing: 16)($sales(), $live(), $stacked(), $scatter(), $donut()),
)->padding(20));
