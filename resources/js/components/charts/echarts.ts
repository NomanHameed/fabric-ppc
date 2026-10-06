import { BarChart, LineChart } from 'echarts/charts';
import {
    DataZoomComponent,
    GridComponent,
    LegendComponent,
    TooltipComponent,
} from 'echarts/components';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';

/**
 * The only ECharts entry point this app uses.
 *
 * Imported piece by piece from `echarts/core` on purpose: `import * as echarts from 'echarts'` pulls in every
 * chart type, every component and both renderers (~1 MB minified), and this app's bundle is already over the
 * size warning. Only what the dashboard and the capacity screen actually draw is registered:
 *
 *  - `BarChart` — production by department (stacked), allocation against capacity (horizontal)
 *  - `LineChart` — the daily flow trend
 *  - `GridComponent` — the cartesian plot area both use
 *  - `TooltipComponent`, `LegendComponent` — read-out and series toggles
 *  - `DataZoomComponent` — a 366-day range has to be scrubbable, on a mouse and on a finger
 *  - `CanvasRenderer` — canvas only; the SVG renderer would be a second renderer in the bundle
 *
 * Adding a chart type means adding its import here, never widening this to the whole package.
 *
 * Registration is idempotent in ECharts, and this module is evaluated once per bundle, so importing it from
 * several components is safe.
 */
use([
    BarChart,
    LineChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DataZoomComponent,
    CanvasRenderer,
]);

export { default as VChart } from 'vue-echarts';
