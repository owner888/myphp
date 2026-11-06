<?php
// A row's page, {i} of /row/{i} its number; $this the Mobile main.php makes.
declare(strict_types=1);

return fn(int $i) => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    Text("这是第 $i 行的详情页。"),
    Text('导航栏的返回、Android 的返回键和手势都回到列表，列表停在原处。')->font(Font::subheadline)->foregroundStyle(Color::secondary),
    Button('再推一页', action: fn() => $this->homePath->append('/row/' . ($i + 1))),
    Spacer(),
)
    ->padding()
    ->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)
    ->navigationTitle("第 $i 行");
