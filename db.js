// db.js - API Client for AgriTrace+
const API_BASE = 'api/db.php';

async function apiRequest(action, table, data = null, id = null) {
  const url = `${API_BASE}?action=${action}&table=${table}${id ? `&id=${id}` : ''}`;
  const options = {
    method: data ? (action === 'insert' ? 'POST' : 'PUT') : 'GET',
    headers: { 'Content-Type': 'application/json' }
  };
  
  if (data) options.body = JSON.stringify(data);
  
  try {
    const response = await fetch(url, options);
    const result = await response.json();
    return result.error ? { error: result.error } : result;
  } catch (error) {
    console.error('API Error:', error);
    return { error: 'Network error' };
  }
}

const DB = {
  async getAll(table) { 
    const result = await apiRequest('getAll', table);
    return result || []; 
  },
  async getById(table, id) { 
    return await apiRequest('getById', table, null, id); 
  },
  async insert(table, data) { 
    return await apiRequest('insert', table, data); 
  },
  async update(table, id, data) { 
    return await apiRequest('update', table, data, id); 
  },
  async delete(table, id) { 
    return await apiRequest('delete', table, null, id); 
  },
  async seed() { 
    return await apiRequest('seed', 'users'); 
  }
};