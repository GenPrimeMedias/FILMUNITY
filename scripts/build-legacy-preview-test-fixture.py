"""Generate synthetic BIFF8 test data; xlwt is test tooling, not an app dependency."""
import io
import sys
import base64

sys.path.insert(0, 'storage/framework/testing/legacy-fixture-tools')
import xlwt

book = xlwt.Workbook()
sheet = book.add_sheet('Synthetic members')
rows = [
    ['Old No', 'Name', 'Email', 'Category', 'Joined', 'Until', 'Phone'],
    ['000123', 'Synthetic Tamil உறுப்பினர்', 'synthetic.member@example.test', 'yearly', '2020-01-01', '2027-01-01', '+910001234567'],
    ['000124', 'Synthetic Life Member', 'synthetic.life@example.test', 'Life-Time', '2019-01-01', '', '00098765'],
]
for y, row in enumerate(rows):
    for x, value in enumerate(row):
        sheet.write(y, x, value)
sheet.write(3, 0, 125)
for x, value in enumerate(['Synthetic Numeric', 'numeric@example.test', 'yearly', '2020-01-01', '2027-01-01', '000111'], 1):
    sheet.write(3, x, value)
sheet.write(4, 0, '000126')
sheet.write(4, 1, xlwt.Formula('1+1'))
for x, value in enumerate(['formula@example.test', 'yearly', '2020-01-01', '2027-01-01', '000112'], 2):
    sheet.write(4, x, value)
buffer = io.BytesIO()
book.save(buffer)
print(base64.b64encode(buffer.getvalue()).decode('ascii'))
