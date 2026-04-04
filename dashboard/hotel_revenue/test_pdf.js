const vm = require('vm');
const fs = require('fs');

const context = {
    document: {
        getElementById: (id) => ({ value: '0', textContent: '100', classList: { add: ()=>{}, remove: ()=>{} }, style:{}, addEventListener: ()=>{} }),
        createElement: () => ({ textContent: '', innerHTML: '', style:{}, addEventListener: ()=>{} }),
        addEventListener: ()=>{}
    },
    window: {
        jspdf: {
            jsPDF: class {
                constructor() { this.internal = { pageSize: { getWidth: ()=>200, getHeight: ()=>300, width: 200, height: 300 }, getNumberOfPages: ()=>8 }; }
                setFillColor() {}
                rect() {}
                circle() {}
                setTextColor() {}
                setFontSize() {}
                setFont() {}
                text() {}
                setDrawColor() {}
                setLineWidth() {}
                line() {}
                addPage() {}
                roundedRect() {}
                autoTable(opts) { this.lastAutoTable = { finalY: opts.startY + 10 }; }
                setPage() {}
                save(name) { console.log("SUCCESSFULLY SAVED PDF:", name); }
            }
        }
    },
    alert: (msg) => console.log("ALERT:", msg),
    console: console,
    Math: Math,
    Date: Date,
    String: String,
    Number: Number,
    Array: Array,
    Object: Object,
    Set: Set,
    parseFloat: parseFloat,
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    localStorage: { getItem: ()=>null, setItem: ()=>null }
};
vm.createContext(context);

// read file and parse
let content = fs.readFileSync('overtime.js', 'utf8');

// evaluate
try {
    vm.runInContext(content, context);
    console.log("File evaluated successfully. Pre-loading mocked data...");
    
    vm.runInContext("auditData = [{checkIn: new Date(), dailyRate: 10000, roomType: 'Standard'}];", context);
    vm.runInContext("overtimeData = [{roomType: 'Standard', checkIn: new Date(), expectedOut: new Date(), actualOut: new Date(), diffHours: 5, dailyRate: 10000, isRectified: false, overtimeCharge: 2000}];", context);
    vm.runInContext("doubleSalesData = []; reconAdjustments = [];", context);
    
    vm.runInContext("exportOvertimePDF()", context);
} catch (e) {
    console.error("CRASH IDENTIFIED:");
    console.error(e);
}
