
//Tablesaw.init();

function loadMaps() {
    const maps = document.querySelectorAll(".map-thumbnail");
   
    maps.forEach((map_el) => {

        const map = L.map(map_el, {zoomControl: false});

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        if(map_el.hasAttribute('data-polyline')) {

            const polyline = atob(map_el.getAttribute('data-polyline'));
            const route = L.Polyline.fromEncoded(polyline);

            route.addTo(map);

            map.fitBounds(route.getBounds());

            const decorator = L.polylineDecorator(route.getLatLngs(), {
                patterns: [
                {
                    offset: '17%',
                    repeat: '33%',
                    symbol: L.Symbol.arrowHead({pixelSize: 10, polygon: false, pathOptions: {stroke: true}})
                }
                ]
            }).addTo(map);

            L.marker(route.getLatLngs()[0]).addTo(map);    
        }
        else {
            map.setView([51,-1], 8);
        }  
    });
}

if (document.readyState === "loading") {   
    document.addEventListener("DOMContentLoaded", loadMaps);
} else {  
    loadMaps();
}


$("#date-sort").data( "tablesaw-sort", function( ascending ) {
    return function( a, b ) {
        if( a.ignored || b.ignored ) {
            return 0;
        }
        let date_a = Date.parse(a.cell.split("(")[0]) || 0;
        let date_b = Date.parse(b.cell.split("(")[0]) || 0;

        if( ascending ) {
            return date_a >= date_b ? 1 : -1;
        } else { // descending
            return date_a < date_b ? 1 : -1;
        }
    };
});

$("#dist-sort").data( "tablesaw-sort", function( ascending ) {
    return function( a, b ) {
        if( a.ignored || b.ignored ) {
            return 0;
        }
        // split off (distance in miles) the tablesaw numeric algorithm does a regex replace that leaves "57km (32.1 miles)" as "5732.1"
        // could be overcome by formatting the output of  round(x, 0) output 1 decimal place
        let aa = parseFloat(a.cell.split("(")[0]);
        let bb = parseFloat(b.cell.split("(")[0]);

        if( ascending ) {
            return aa >= bb ? 1 : -1;
        } else { // descending
            return aa < bb ? 1 : -1;
        }
    };
});

$("#tile-sort").data( "tablesaw-sort", function( ascending ) {
    return function( a, b ) {
        if( a.ignored || b.ignored ) {
            return 0;
        }
        let aa = parseFloat(a.cell) || 0;
        let bb = parseFloat(b.cell) || 0;

        if( ascending ) {
            return aa >= bb ? 1 : -1;
        } else { // descending
            return aa < bb ? 1 : -1;
        }
    };
});
