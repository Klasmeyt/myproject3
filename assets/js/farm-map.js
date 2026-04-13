/**
 * AgriTrace+ Philippines Farm Map
 * Full Implementation: Search, District Filtering, and Live Data
 */

let map, farmsLayer, allFarmsData = []; 
const philippinesBounds = [[4.5, 116.9], [21.2, 127.0]];

// Coordinates for the 1st District of Camarines Sur
const district1Coords = {
    "Ragay": [13.8189, 122.7911],
    "Lupi": [13.7844, 122.8686],
    "Sipocot": [13.7636, 122.9733],
    "Del Gallego": [13.9111, 122.5858],
    "Cabusao": [13.7167, 123.1167]
};

document.addEventListener('DOMContentLoaded', () => {
    initMap();
});

function initMap() {
    // 1. Initialize Map centered on Camarines Sur area
    map = L.map('map', {
        minZoom: 6,
        maxBounds: philippinesBounds,
        maxBoundsViscosity: 1.0
    }).setView([13.7636, 122.9733], 10);

    // 2. Add OpenStreetMap Tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap | AgriTrace+',
        maxZoom: 18
    }).addTo(map);

    // 3. Add Search Box (Leaflet Control Geocoder)
    if (typeof L.Control.Geocoder !== 'undefined') {
        L.Control.geocoder({
            defaultMarkGeocode: true,
            placeholder: "Search location...",
            geocoder: L.Control.Geocoder.nominatim({
                geocodingQueryParams: { countrycodes: 'ph' }
            })
        }).addTo(map);
    }

    // 4. Add UI Components
    addLegend();
    addDistrictFilter();
    
    // 5. Load Data from your PHP API
    loadFarmData();
}

async function loadFarmData() {
    showLoading();
    try {
        // IMPORTANT: Adjust this path to match where your db.php actually sits
        const response = await fetch('../api/db.php?action=query&table=farms&join=LEFT JOIN users ON farms.ownerId=users.id');
        const result = await response.json();
        
        // Handle different JSON structures (some APIs wrap data in a .data property)
        allFarmsData = result.data || result; 

        renderFarms(allFarmsData);
        hideLoading();
    } catch (error) {
        console.error('Data Load Error:', error);
        // If data fails to load, we still hide the loading screen so the map is visible
        hideLoading();
    }
}

function renderFarms(farms) {
    if (farmsLayer) map.removeLayer(farmsLayer);
    farmsLayer = L.layerGroup().addTo(map);

    farms.forEach(farm => {
        if (!farm.latitude || !farm.longitude) return;

        const count = parseInt(farm.total_livestock) || 0;
        
        // Color Logic with opacity for transparent circles
        let fillColor = '#94a3b8', strokeColor = '#64748b'; // Default Gray (<5)
        let radius = Math.max(8, Math.min(25, count * 1.2)); // Scale radius by count
        
        if (count >= 5 && count <= 10) {
            fillColor = '#10b981'; // Green
            strokeColor = '#059669';
        } else if (count > 10 && count <= 20) {
            fillColor = '#f59e0b'; // Yellow/Orange
            strokeColor = '#d97706';
        } else if (count > 20) {
            fillColor = '#ef4444'; // Red
            strokeColor = '#dc2626';
        }

        // Create CIRCLE MARKER (transparent circle)
        const circle = L.circleMarker([parseFloat(farm.latitude), parseFloat(farm.longitude)], {
            radius: radius,
            fillColor: fillColor,
            color: strokeColor,
            weight: 3,
            opacity: 0.9,
            fillOpacity: 0.7, // TRANSPARENT FILL
            stroke: true,
            className: 'farm-circle'
        });

        const popupContent = `
            <div class="farm-popup" style="font-family: 'Inter', sans-serif; min-width: 280px; max-width: 300px;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 2px solid #e2e8f0;">
                    <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, ${fillColor}, ${strokeColor}); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        ${count}
                    </div>
                    <div>
                        <h3 style="margin: 0 0 4px 0; font-size: 18px; font-weight: 700; color: #1e293b;">${farm.name}</h3>
                        <div style="color: #64748b; font-size: 14px;">${farm.type} Farm</div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 90px 1fr; gap: 8px 16px; font-size: 14px; line-height: 1.5;">
                    <span style="font-weight: 600; color: #374151;">Owner:</span>
                    <span>${farm.ownerName || 'N/A'}</span>
                    
                    <span style="font-weight: 600; color: #374151;">Livestock:</span>
                    <span style="color: ${fillColor}; font-weight: 700; font-size: 16px;">${count.toLocaleString()} heads</span>
                    
                    <span style="font-weight: 600; color: #374151;">Status:</span>
                    <span style="color: ${farm.status === 'Approved' ? '#10b981' : '#f59e0b'}; font-weight: 600;">${farm.status}</span>
                    
                    <span style="font-weight: 600; color: #374151;">Location:</span>
                    <span style="font-size: 13px; color: #6b7280;">${farm.address || 'N/A'}</span>
                </div>
                ${farm.ownerMobile ? `<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f3f4f6; font-size: 13px;">
                    <span style="font-weight: 600; color: #374151;">Contact:</span>
                    <a href="tel:${farm.ownerMobile}" style="color: #3b82f6; text-decoration: none;">${farm.ownerMobile}</a>
                </div>` : ''}
            </div>`;

        circle.bindPopup(popupContent, {
            maxWidth: 320,
            className: 'farm-popup-container'
        });

        // Add click handler to open sidebar
        circle.on('click', () => openSidebar(farm));

        circle.addTo(farmsLayer);
    });
}

function openSidebar(farm) {
    const sidebar = document.getElementById('sidebar');
    const details = document.getElementById('farmDetails');
    
    details.innerHTML = `
        <div style="font-family: 'Inter', sans-serif;">
            <div style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; border-radius: 12px; text-align: center; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 24px; font-weight: 700;">${farm.name}</h2>
                <p style="margin: 8px 0 0 0; opacity: 0.9;">${farm.type} Farm</p>
            </div>
            <div style="display: grid; grid-template-columns: 100px 1fr; gap: 12px; font-size: 14px;">
                <span style="font-weight: 600; color: #374151;">Owner:</span>
                <span style="color: #1e293b; font-weight: 500;">${farm.ownerName}</span>
                
                <span style="font-weight: 600; color: #374151;">Livestock:</span>
                <span style="color: #ef4444; font-weight: 700; font-size: 18px;">${parseInt(farm.total_livestock).toLocaleString()} heads</span>
                
                <span style="font-weight: 600; color: #374151;">Status:</span>
                <span style="color: ${farm.status === 'Approved' ? '#10b981' : '#f59e0b'}; font-weight: 600; font-size: 14px;">${farm.status}</span>
                
                <span style="font-weight: 600; color: #374151;">Address:</span>
                <span style="color: #6b7280; font-size: 13px;">${farm.address}</span>
            </div>
        </div>
    `;
    
    sidebar.style.display = 'block';
    map.closePopup();
}

function closeSidebar() {
    document.getElementById('sidebar').style.display = 'none';
}

function filterByDistrict(townName) {
    if (townName === "all") {
        renderFarms(allFarmsData);
        map.setView([12.8797, 121.7740], 6); // Reset to national view
        return;
    }

    // Filters data where the address field includes the town name
    const filtered = allFarmsData.filter(farm => {
        const addr = (farm.address || "").toLowerCase();
        return addr.includes(townName.toLowerCase());
    });

    renderFarms(filtered);

    // Move map to the town coordinates defined at the top
    if (district1Coords[townName]) {
        map.setView(district1Coords[townName], 14);
    }
}

function addDistrictFilter() {
    const filterContainer = L.control({ position: 'topright' });
    filterContainer.onAdd = function() {
        const div = L.DomUtil.create('div', 'filter-ctrl');
        // Inline styles to ensure it looks good without external CSS dependency
        div.style.cssText = 'background:white; padding:10px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border: 1px solid #ccc;';
        
        div.innerHTML = `
            <label style="font-weight:bold; display:block; margin-bottom:5px; font-family:sans-serif; font-size:12px;">Cam Sur 1st District:</label>
            <select onchange="filterByDistrict(this.value)" style="padding:5px; width:140px; border:1px solid #ddd; border-radius:4px; font-size:13px; cursor:pointer;">
                <option value="all">Show All PH</option>
                <option value="Cabusao">Cabusao</option>
                <option value="Del Gallego">Del Gallego</option>
                <option value="Lupi">Lupi</option>
                <option value="Ragay">Ragay</option>
                <option value="Sipocot">Sipocot</option>
            </select>
        `;
        return div;
    };
    filterContainer.addTo(map);
}

function addLegend() {
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = function() {
        const div = L.DomUtil.create('div', 'legend');
        div.style.cssText = 'background:white; padding:12px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); font-family:sans-serif; font-size:12px; line-height:1.8;';
        div.innerHTML = `
            <b style="display:block; margin-bottom:5px; font-size:13px;">Livestock Population</b>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#10b981; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> 5 - 10 (Low)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#f59e0b; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> 11 - 20 (Medium)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#ef4444; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> 20+ (High Risk)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i style="background:#94a3b8; width:14px; height:14px; display:inline-block; border-radius:3px;"></i> < 5 (Minimal)</div>
        `;
        return div;
    };
    legend.addTo(map);
}

function showLoading() {
    const loader = document.getElementById('loadingOverlay');
    if (loader) loader.style.display = 'flex';
}

function hideLoading() {
    const loader = document.getElementById('loadingOverlay');
    if (loader) loader.style.display = 'none';
}