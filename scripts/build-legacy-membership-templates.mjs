// Development asset builder. Resolve the provided artifact runtime via the ignored
// storage/framework/testing/legacy-template-builder/node_modules junction.
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
const root = process.cwd();
const requireRuntime = createRequire(path.join(root, 'storage/framework/testing/legacy-template-builder/package.json'));
const { Workbook, SpreadsheetFile } = await import(pathToFileURL(requireRuntime.resolve('@oai/artifact-tool')).href);
const spec = JSON.parse(execFileSync('php', ['scripts/legacy-template-definition.php'], { cwd: root, encoding: 'utf8' }));
const out = path.join(root, 'resources/templates/legacy-membership');
const previews = path.join(root, 'storage/framework/testing/legacy-template-builder');
await fs.mkdir(out, { recursive: true });
await fs.writeFile(path.join(out, 'filmunity-members-blank.csv'), '\uFEFF' + spec.headers.join(',') + '\r\n', 'utf8');
for (const example of [false, true]) {
  const workbook = Workbook.create();
  const members = workbook.worksheets.add('Members');
  const guidance = workbook.worksheets.add('Guidance');
  members.showGridLines = false; guidance.showGridLines = false;
  members.getRange('A1:H1').values = [spec.headers];
  members.getRange('A1:H1').format = { fill: '#40572D', font: { name: 'Arial', size: 11, color: '#FFFFFF', bold: true }, rowHeight: 28 };
  members.getRange('A2:F2001').setNumberFormat('@');
  members.getRange('G2:H2001').setNumberFormat('yyyy-mm-dd');
  members.getRange('A2:H12').format.font = { name: 'Arial', size: 11 };
  members.getRange('A1:H12').format.columnWidth = 22;
  members.getRange('B1:B12').format.columnWidth = 44;
  members.getRange('C1:C12').format.columnWidth = 42;
  members.getRange('E1:E12').format.columnWidth = 40;
  members.getRange('A2:H12').format.rowHeight = 38;
  members.getRange('B2:B12').format.wrapText = true;
  members.freezePanes.freezeRows(1);
  members.getRange('F2:F2001').dataValidation = { rule: { type: 'list', values: spec.categories } };
  if (example) {
    const values = spec.example.map(row => row.map((v, i) => i >= 6 && v ? new Date(`${v}T00:00:00.000Z`) : v));
    members.getRange('A2:H3').values = values;
    members.getRange('A2:A3').setNumberFormat('@');
    members.getRange('D2:D3').setNumberFormat('@');
  }
  guidance.getRange(`A1:B${spec.guidance.length}`).values = spec.guidance;
  guidance.getRange(`A1:B${spec.guidance.length}`).format.font = { name: 'Arial', size: 11 };
  guidance.getRange('A1:A21').format.columnWidth = 42;
  guidance.getRange('B1:B21').format.columnWidth = 105;
  guidance.getRange('A1:B21').format.wrapText = true;
  guidance.getRange('A1:B21').format.rowHeight = 43;
  guidance.getRange('A1:B1').format = { fill: '#40572D', font: { name: 'Arial', color: '#FFFFFF', bold: true, size: 12 }, rowHeight: 42 };
  guidance.getRange('B17').setNumberFormat('@');
  workbook.recalculate();
  console.log((await workbook.inspect({ kind: 'table', range: 'Members!A1:H3', include: 'values', tableMaxRows: 3, tableMaxCols: 8 })).ndjson);
  const output = await SpreadsheetFile.exportXlsx(workbook);
  await output.save(path.join(out, example ? 'filmunity-members-example.xlsx' : 'filmunity-members-blank.xlsx'));
  const sidecar = path.join(out, `${example ? 'filmunity-members-example.xlsx' : 'filmunity-members-blank.xlsx'}.inspect.ndjson`);
  try { await fs.rename(sidecar, path.join(previews, example ? 'example.inspect.ndjson' : 'blank.inspect.ndjson')); } catch (error) { if (error.code !== 'ENOENT') throw error; }
  for (const sheetName of ['Members', 'Guidance']) {
    const preview = await workbook.render({ sheetName, range: sheetName === 'Members' ? 'A1:H4' : 'A1:B21', scale: 1, format: 'png' });
    await fs.writeFile(path.join(previews, `${example ? 'example' : 'blank'}-${sheetName}.png`), new Uint8Array(await preview.arrayBuffer()));
  }
}
