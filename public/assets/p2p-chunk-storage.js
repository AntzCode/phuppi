/**
 * P2P Chunk Storage - IndexedDB-based chunk persistence for resumable P2P file transfers
 * 
 * @author Anthony Gallon, Owner/Licensor: AntzCode Ltd <https://www.antzcode.com>
 * @contact https://github.com/AntzCode
 * 
 * Provides IndexedDB operations for storing and retrieving file chunks
 * to enable resumable peer-to-peer file transfers.
 */

// Database configuration
const DB_NAME = 'phuppi-p2p-chunks';
const DB_VERSION = 2;
const CHUNK_STORE_NAME = 'chunks';
const METADATA_STORE_NAME = 'metadata';
const COMPLETED_STORE_NAME = 'completed';
const CHUNK_SIZE = 16 * 1024; // 16KB default chunk size

/**
 * @typedef {Object} FileMetadata
 * @property {string} fileId
 * @property {string} name
 * @property {number} size
 * @property {string} mimeType
 * @property {number} totalChunks
 * @property {number} lastModified
 */

/**
 * @typedef {Object} ChunkData
 * @property {string} fileId
 * @property {number} chunkIndex
 * @property {ArrayBuffer} data
 */

/**
 * Opens the IndexedDB database
 * @returns {Promise<IDBDatabase>}
 */
function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onerror = () => {
            reject(new Error('Failed to open IndexedDB database'));
        };

        request.onsuccess = () => {
            resolve(request.result);
        };

        request.onupgradeneeded = (event) => {
            const db = event.target.result;

            // Create chunks object store with composite index
            if (!db.objectStoreNames.contains(CHUNK_STORE_NAME)) {
                const chunkStore = db.createObjectStore(CHUNK_STORE_NAME, { keyPath: ['fileId', 'chunkIndex'] });
                // Create index for fileId to enable getting all chunks for a file
                chunkStore.createIndex('fileId', 'fileId', { unique: false });
            }

            // Create metadata object store
            if (!db.objectStoreNames.contains(METADATA_STORE_NAME)) {
                db.createObjectStore(METADATA_STORE_NAME, { keyPath: 'fileId' });
            }

            // Create completed files object store (v2)
            if (!db.objectStoreNames.contains(COMPLETED_STORE_NAME)) {
                db.createObjectStore(COMPLETED_STORE_NAME, { keyPath: 'fileId' });
            }
        };
    });
}

/**
 * Saves a chunk to IndexedDB
 * Uses put to update if the chunk already exists
 * 
 * @param {string} fileId - Unique identifier for the file
 * @param {number} chunkIndex - Index of the chunk
 * @param {ArrayBuffer} data - The chunk data as ArrayBuffer
 * @returns {Promise<void>}
 */
async function saveChunk(fileId, chunkIndex, data) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([CHUNK_STORE_NAME], 'readwrite');
        const store = transaction.objectStore(CHUNK_STORE_NAME);

        const chunkData = {
            fileId: fileId,
            chunkIndex: chunkIndex,
            data: data
        };

        const request = store.put(chunkData);

        request.onsuccess = () => {
            resolve();
        };

        request.onerror = () => {
            reject(new Error(`Failed to save chunk ${chunkIndex} for file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Retrieves a single chunk by fileId and chunkIndex
 * 
 * @param {string} fileId - Unique identifier for the file
 * @param {number} chunkIndex - Index of the chunk
 * @returns {Promise<ArrayBuffer|null>} - The chunk data or null if not found
 */
async function getChunk(fileId, chunkIndex) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([CHUNK_STORE_NAME], 'readonly');
        const store = transaction.objectStore(CHUNK_STORE_NAME);

        const request = store.get([fileId, chunkIndex]);

        request.onsuccess = () => {
            const result = request.result;
            resolve(result ? result.data : null);
        };

        request.onerror = () => {
            reject(new Error(`Failed to get chunk ${chunkIndex} for file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Gets all chunk indices received for a file
 * 
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<Set<number>>} - Set of chunk indices
 */
async function getReceivedChunks(fileId) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([CHUNK_STORE_NAME], 'readonly');
        const store = transaction.objectStore(CHUNK_STORE_NAME);
        const index = store.index('fileId');
        const range = IDBKeyRange.only(fileId);

        const receivedChunks = new Set();
        const request = index.openCursor(range);

        request.onsuccess = (event) => {
            const cursor = event.target.result;
            if (cursor) {
                receivedChunks.add(cursor.value.chunkIndex);
                cursor.continue();
            }
        };

        transaction.oncomplete = () => {
            db.close();
            resolve(receivedChunks);
        };

        transaction.onerror = () => {
            reject(new Error(`Failed to get received chunks for file ${fileId}`));
        };
    });
}

/**
 * Gets file metadata
 * 
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<FileMetadata|null>} - File metadata or null if not found
 */
async function getMetadata(fileId) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([METADATA_STORE_NAME], 'readonly');
        const store = transaction.objectStore(METADATA_STORE_NAME);

        const request = store.get(fileId);

        request.onsuccess = () => {
            resolve(request.result || null);
        };

        request.onerror = () => {
            reject(new Error(`Failed to get metadata for file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Saves file metadata
 * 
 * @param {string} fileId - Unique identifier for the file
 * @param {Object} metadata - Metadata object containing name, size, mimeType, totalChunks, lastModified
 * @returns {Promise<void>}
 */
async function saveMetadata(fileId, metadata) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([METADATA_STORE_NAME], 'readwrite');
        const store = transaction.objectStore(METADATA_STORE_NAME);

        const metadataObject = {
            fileId: fileId,
            name: metadata.name,
            size: metadata.size,
            mimeType: metadata.mimeType,
            totalChunks: metadata.totalChunks,
            lastModified: metadata.lastModified
        };

        const request = store.put(metadataObject);

        request.onsuccess = () => {
            resolve();
        };

        request.onerror = () => {
            reject(new Error(`Failed to save metadata for file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Gets all fileIds that have chunks stored
 * 
 * @returns {Promise<string[]>} - Array of fileIds
 */
async function getAllFileIds() {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([CHUNK_STORE_NAME], 'readonly');
        const store = transaction.objectStore(CHUNK_STORE_NAME);
        const index = store.index('fileId');

        const fileIds = new Set();
        const request = index.openCursor();

        request.onsuccess = (event) => {
            const cursor = event.target.result;
            if (cursor) {
                fileIds.add(cursor.value.fileId);
                cursor.continue();
            }
        };

        transaction.oncomplete = () => {
            db.close();
            resolve(Array.from(fileIds));
        };

        transaction.onerror = () => {
            reject(new Error('Failed to get all fileIds'));
        };
    });
}

/**
 * Removes all chunks and metadata for a file
 * 
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<void>}
 */
async function clearFile(fileId) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        // Use a transaction that includes both stores
        const transaction = db.transaction([CHUNK_STORE_NAME, METADATA_STORE_NAME], 'readwrite');

        // Delete all chunks for this file using the index
        const chunkStore = transaction.objectStore(CHUNK_STORE_NAME);
        const chunkIndex = chunkStore.index('fileId');
        const chunkRange = IDBKeyRange.only(fileId);
        const chunkRequest = chunkIndex.openCursor(chunkRange);

        chunkRequest.onsuccess = (event) => {
            const cursor = event.target.result;
            if (cursor) {
                cursor.delete();
                cursor.continue();
            }
        };

        // Delete metadata for this file
        const metadataStore = transaction.objectStore(METADATA_STORE_NAME);
        metadataStore.delete(fileId);

        transaction.oncomplete = () => {
            db.close();
            resolve();
        };

        transaction.onerror = () => {
            reject(new Error(`Failed to clear file ${fileId}`));
        };
    });
}

/**
 * Reconstructs a file from its chunks
 * Gets all chunks sorted by chunkIndex and concatenates them into a single ArrayBuffer
 * 
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<{blob: Blob, metadata: FileMetadata}>} - Reconstructed file blob and metadata
 */
async function reconstructFile(fileId) {
    const [metadata, receivedChunks] = await Promise.all([
        getMetadata(fileId),
        getReceivedChunks(fileId)
    ]);

    if (!metadata) {
        throw new Error(`No metadata found for file ${fileId}`);
    }

    if (receivedChunks.size === 0) {
        throw new Error(`No chunks found for file ${fileId}`);
    }

    // Sort chunk indices
    const sortedIndices = Array.from(receivedChunks).sort((a, b) => a - b);

    // Collect all chunks in order
    const chunks = [];
    for (const index of sortedIndices) {
        const chunkData = await getChunk(fileId, index);
        if (chunkData) {
            chunks.push(chunkData);
        }
    }

    // Calculate total size and concatenate chunks
    const totalSize = chunks.reduce((sum, chunk) => sum + chunk.byteLength, 0);
    const concatenatedBuffer = new Uint8Array(totalSize);

    let offset = 0;
    for (const chunk of chunks) {
        const chunkArray = new Uint8Array(chunk);
        concatenatedBuffer.set(chunkArray, offset);
        offset += chunkArray.length;
    }

    // Create Blob from the concatenated buffer
    const blob = new Blob([concatenatedBuffer], { type: metadata.mimeType });

    return {
        blob: blob,
        metadata: metadata
    };
}

/**
 * Generates a unique fileId from a File object
 * Uses base64 encoding of name:size:lastModified
 * 
 * @param {File} file - The File object
 * @returns {string} - Unique file identifier
 */
function getFileId(file) {
    return btoa(file.name + ':' + file.size + ':' + file.lastModified);
}

/**
 * Calculates the total number of chunks needed for a file
 * 
 * @param {number} fileSize - Size of the file in bytes
 * @param {number} chunkSize - Size of each chunk in bytes (default: 16KB)
 * @returns {number} - Total number of chunks
 */
function getTotalChunks(fileSize, chunkSize = CHUNK_SIZE) {
    return Math.ceil(fileSize / chunkSize);
}

/**
 * Marks a file as completed by storing its metadata in the completed store
 * 
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<void>}
 */
async function markFileComplete(fileId) {
    const metadata = await getMetadata(fileId);
    
    if (!metadata) {
        throw new Error(`No metadata found for file ${fileId}`);
    }

    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([COMPLETED_STORE_NAME], 'readwrite');
        const store = transaction.objectStore(COMPLETED_STORE_NAME);

        const completedRecord = {
            fileId: fileId,
            name: metadata.name,
            size: metadata.size,
            mimeType: metadata.mimeType,
            totalChunks: metadata.totalChunks,
            lastModified: metadata.lastModified,
            completedAt: Date.now()
        };

        const request = store.put(completedRecord);

        request.onsuccess = () => {
            resolve();
        };

        request.onerror = () => {
            reject(new Error(`Failed to mark file ${fileId} as complete`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Gets a completed file's metadata
 * 
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<Object|null>} - Completed file metadata or null if not found
 */
async function getCompletedFile(fileId) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([COMPLETED_STORE_NAME], 'readonly');
        const store = transaction.objectStore(COMPLETED_STORE_NAME);

        const request = store.get(fileId);

        request.onsuccess = () => {
            resolve(request.result || null);
        };

        request.onerror = () => {
            reject(new Error(`Failed to get completed file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Gets all completed files
 * 
 * @returns {Promise<Object[]>} - Array of completed file metadata
 */
async function getAllCompletedFiles() {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([COMPLETED_STORE_NAME], 'readonly');
        const store = transaction.objectStore(COMPLETED_STORE_NAME);

        const request = store.getAll();

        request.onsuccess = () => {
            resolve(request.result || []);
        };

        request.onerror = () => {
            reject(new Error('Failed to get all completed files'));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Removes a file from the completed store
 * 
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<void>}
 */
async function removeCompletedFile(fileId) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([COMPLETED_STORE_NAME], 'readwrite');
        const store = transaction.objectStore(COMPLETED_STORE_NAME);

        const request = store.delete(fileId);

        request.onsuccess = () => {
            resolve();
        };

        request.onerror = () => {
            reject(new Error(`Failed to remove completed file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

// ============================================
// Chunk Buffer for Batched Writes
// ============================================

/**
 * @typedef {Object} ChunkBuffer
 * @property {string} fileId
 * @property {Map<number, ArrayBuffer>} chunks
 */

// Chunk buffer storage - keyed by fileId
const chunkBuffers = new Map();

// Batch size configuration
const CHUNK_BATCH_SIZE = 50; // Save chunks in batches of 50
const CHUNK_FLUSH_INTERVAL = 1000; // Force flush every 1 second if buffer not full

/**
 * Add a chunk to the buffer for batched saving
 * @param {string} fileId - Unique identifier for the file
 * @param {number} chunkIndex - Index of the chunk
 * @param {ArrayBuffer} data - The chunk data as ArrayBuffer
 */
async function bufferChunk(fileId, chunkIndex, data) {
    if (!chunkBuffers.has(fileId)) {
        chunkBuffers.set(fileId, new Map());
    }
    
    const buffer = chunkBuffers.get(fileId);
    buffer.set(chunkIndex, data);
    
    // Auto-flush when batch size reached
    if (buffer.size >= CHUNK_BATCH_SIZE) {
        await flushChunkBuffer(fileId);
    }
}

/**
 * Flush the chunk buffer for a specific file - saves all buffered chunks to IndexedDB
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<void>}
 */
async function flushChunkBuffer(fileId) {
    const buffer = chunkBuffers.get(fileId);
    if (!buffer || buffer.size === 0) {
        return;
    }
    
    const chunksToSave = new Map(buffer);
    buffer.clear();
    
    const db = await openDatabase();
    
    return new Promise((resolve, reject) => {
        const transaction = db.transaction([CHUNK_STORE_NAME], 'readwrite');
        const store = transaction.objectStore(CHUNK_STORE_NAME);
        
        let savedCount = 0;
        let errorCount = 0;
        
        for (const [chunkIndex, data] of chunksToSave) {
            const chunkData = {
                fileId: fileId,
                chunkIndex: chunkIndex,
                data: data
            };
            
            const request = store.put(chunkData);
            
            request.onsuccess = () => {
                savedCount++;
            };
            
            request.onerror = () => {
                errorCount++;
                console.error(`Failed to save chunk ${chunkIndex} for file ${fileId}`);
            };
        }
        
        transaction.oncomplete = () => {
            db.close();
            if (errorCount > 0) {
                console.warn(`Flushed ${chunksToSave.size} chunks for ${fileId}: ${savedCount} saved, ${errorCount} errors`);
            }
            resolve();
        };
        
        transaction.onerror = () => {
            db.close();
            reject(new Error(`Transaction failed for file ${fileId}`));
        };
    });
}

/**
 * Flush all chunk buffers for all files
 * @returns {Promise<void>}
 */
async function flushAllChunkBuffers() {
    const fileIds = Array.from(chunkBuffers.keys());
    for (const fileId of fileIds) {
        await flushChunkBuffer(fileId);
    }
}

/**
 * Get the number of buffered chunks for a file (not yet saved to IndexedDB)
 * @param {string} fileId - Unique identifier for the file
 * @returns {number}
 */
function getBufferedChunkCount(fileId) {
    const buffer = chunkBuffers.get(fileId);
    return buffer ? buffer.size : 0;
}

// ============================================
// OPFS (Origin Private File System) - Sender-side file persistence
// ============================================

const OPFS_ROOT_NAME = 'phuppi-sender-files';

/**
 * Check if OPFS is available in the current browser
 * @returns {Promise<boolean>}
 */
async function isOPFSAvailable() {
    try {
        return 'storage' in navigator && await navigator.storage.getDirectory();
    } catch (e) {
        console.warn('OPFS not available:', e);
        return false;
    }
}

/**
 * Store a file in OPFS
 * @param {string} fileId - Unique identifier for the file
 * @param {File} file - The File object to store
 * @returns {Promise<FileSystemFileHandle>}
 */
async function storeFileInOPFS(fileId, file) {
    const root = await navigator.storage.getDirectory();
    const fileHandle = await root.getFileHandle(fileId, { create: true });
    const writable = await fileHandle.createWritable();
    await writable.write(file);
    await writable.close();
    return fileHandle;
}

/**
 * Get a file handle from OPFS
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<FileSystemFileHandle>}
 */
async function getFileFromOPFS(fileId) {
    const root = await navigator.storage.getDirectory();
    return await root.getFileHandle(fileId);
}

/**
 * Check if a file exists in OPFS
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<boolean>}
 */
async function fileExistsInOPFS(fileId) {
    try {
        const root = await navigator.storage.getDirectory();
        await root.getFileHandle(fileId);
        return true;
    } catch (e) {
        return false;
    }
}

/**
 * Delete a file from OPFS
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<boolean>}
 */
async function deleteFileFromOPFS(fileId) {
    try {
        const root = await navigator.storage.getDirectory();
        await root.removeEntry(fileId);
        return true;
    } catch (e) {
        console.error('Failed to delete OPFS file:', e);
        return false;
    }
}

/**
 * Read a specific chunk from an OPFS file (for efficient resume support)
 * @param {string} fileId - Unique identifier for the file
 * @param {number} chunkIndex - Index of the chunk
 * @param {number} chunkSize - Size of each chunk in bytes
 * @returns {Promise<ArrayBuffer>}
 */
async function readOPFSChunk(fileId, chunkIndex, chunkSize) {
    const fileHandle = await getFileFromOPFS(fileId);
    const file = await fileHandle.getFile();
    const start = chunkIndex * chunkSize;
    const end = Math.min(start + chunkSize, file.size);
    
    if (start >= file.size) {
        return new ArrayBuffer(0);
    }
    
    const slice = file.slice(start, end);
    return await slice.arrayBuffer();
}

/**
 * Get file from OPFS as a File object for reading
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<File>}
 */
async function getOPFSFileAsFile(fileId) {
    const fileHandle = await getFileFromOPFS(fileId);
    return await fileHandle.getFile();
}

/**
 * Delete all files from OPFS
 * @returns {Promise<void>}
 */
async function clearAllOPFSFiles() {
    try {
        const root = await navigator.storage.getDirectory();
        for await (const entry of root.values()) {
            if (entry.kind === 'file') {
                await root.removeEntry(entry.name);
            }
        }
    } catch (e) {
        console.error('Failed to clear OPFS:', e);
    }
}

// ============================================
// Sender Metadata Store (IndexedDB) - for session restoration
// ============================================

const SENDER_METADATA_STORE = 'sender-metadata';

/**
 * Open database with sender metadata store (creates if doesn't exist)
 * @returns {Promise<IDBDatabase>}
 */
function openDatabaseWithSenderMetadata() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION + 1);

        request.onerror = () => {
            reject(new Error('Failed to open IndexedDB database'));
        };

        request.onsuccess = () => {
            resolve(request.result);
        };

        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            
            // Create sender metadata object store if it doesn't exist
            if (!db.objectStoreNames.contains(SENDER_METADATA_STORE)) {
                db.createObjectStore(SENDER_METADATA_STORE, { keyPath: 'fileId' });
            }
        };
    });
}

/**
 * Save sender file metadata to IndexedDB
 * @param {Object} metadata - Metadata object containing fileId, name, size, type, lastModified
 * @returns {Promise<void>}
 */
async function saveSenderFileMetadata(metadata) {
    const db = await openDatabaseWithSenderMetadata();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([SENDER_METADATA_STORE], 'readwrite');
        const store = transaction.objectStore(SENDER_METADATA_STORE);

        const metadataObject = {
            fileId: metadata.fileId,
            name: metadata.name,
            size: metadata.size,
            type: metadata.type,
            lastModified: metadata.lastModified,
            storedAt: Date.now()
        };

        const request = store.put(metadataObject);

        request.onsuccess = () => {
            resolve();
        };

        request.onerror = () => {
            reject(new Error(`Failed to save sender metadata for file ${metadata.fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Get sender file metadata from IndexedDB
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<Object|null>}
 */
async function getSenderFileMetadata(fileId) {
    const db = await openDatabaseWithSenderMetadata();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([SENDER_METADATA_STORE], 'readonly');
        const store = transaction.objectStore(SENDER_METADATA_STORE);

        const request = store.get(fileId);

        request.onsuccess = () => {
            resolve(request.result || null);
        };

        request.onerror = () => {
            reject(new Error(`Failed to get sender metadata for file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Get all sender file metadata from IndexedDB
 * @returns {Promise<Object[]>}
 */
async function getAllSenderFileMetadata() {
    const db = await openDatabaseWithSenderMetadata();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([SENDER_METADATA_STORE], 'readonly');
        const store = transaction.objectStore(SENDER_METADATA_STORE);

        const request = store.getAll();

        request.onsuccess = () => {
            resolve(request.result || []);
        };

        request.onerror = () => {
            reject(new Error('Failed to get all sender metadata'));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Delete sender file metadata from IndexedDB
 * @param {string} fileId - Unique identifier for the file
 * @returns {Promise<void>}
 */
async function deleteSenderFileMetadata(fileId) {
    const db = await openDatabaseWithSenderMetadata();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([SENDER_METADATA_STORE], 'readwrite');
        const store = transaction.objectStore(SENDER_METADATA_STORE);

        const request = store.delete(fileId);

        request.onsuccess = () => {
            resolve();
        };

        request.onerror = () => {
            reject(new Error(`Failed to delete sender metadata for file ${fileId}`));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Clear all sender metadata from IndexedDB
 * @returns {Promise<void>}
 */
async function clearAllSenderMetadata() {
    const db = await openDatabaseWithSenderMetadata();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction([SENDER_METADATA_STORE], 'readwrite');
        const store = transaction.objectStore(SENDER_METADATA_STORE);

        const request = store.clear();

        request.onsuccess = () => {
            resolve();
        };

        request.onerror = () => {
            reject(new Error('Failed to clear sender metadata'));
        };

        transaction.oncomplete = () => {
            db.close();
        };
    });
}

/**
 * Complete sender cleanup - removes all OPFS files and metadata
 * Call this when a session is cancelled or completed
 * @returns {Promise<void>}
 */
async function cleanupSenderFiles() {
    await clearAllOPFSFiles();
    await clearAllSenderMetadata();
    console.log('Sender files cleanup complete');
}

/**
 * Restore sender files from OPFS and IndexedDB on page load
 * Returns array of file metadata with OPFS handles
 * @returns {Promise<Array<Object>>}
 */
async function restoreSenderFiles() {
    const opfsAvailable = await isOPFSAvailable();
    if (!opfsAvailable) {
        console.warn('OPFS not available, cannot restore files');
        return [];
    }

    const allMetadata = await getAllSenderFileMetadata();
    const restoredFiles = [];

    for (const metadata of allMetadata) {
        const exists = await fileExistsInOPFS(metadata.fileId);
        if (exists) {
            try {
                const file = await getOPFSFileAsFile(metadata.fileId);
                restoredFiles.push({
                    fileId: metadata.fileId,
                    name: metadata.name,
                    size: metadata.size,
                    type: metadata.type,
                    lastModified: metadata.lastModified,
                    file: file // The actual File object from OPFS
                });
            } catch (e) {
                console.error('Error restoring file from OPFS:', metadata.fileId, e);
            }
        } else {
            console.warn('File exists in metadata but not in OPFS:', metadata.fileId);
        }
    }

    return restoredFiles;
}

// Export functions for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        openDatabase,
        saveChunk,
        getChunk,
        getReceivedChunks,
        getMetadata,
        saveMetadata,
        getAllFileIds,
        clearFile,
        reconstructFile,
        getFileId,
        getTotalChunks,
        markFileComplete,
        getCompletedFile,
        getAllCompletedFiles,
        removeCompletedFile,
        // Batch chunk functions
        bufferChunk,
        flushChunkBuffer,
        flushAllChunkBuffers,
        getBufferedChunkCount,
        // OPFS functions
        isOPFSAvailable,
        storeFileInOPFS,
        getFileFromOPFS,
        fileExistsInOPFS,
        deleteFileFromOPFS,
        readOPFSChunk,
        getOPFSFileAsFile,
        clearAllOPFSFiles,
        // Sender metadata functions
        saveSenderFileMetadata,
        getSenderFileMetadata,
        getAllSenderFileMetadata,
        deleteSenderFileMetadata,
        clearAllSenderMetadata,
        cleanupSenderFiles,
        restoreSenderFiles,
        CHUNK_SIZE,
        DB_NAME,
        DB_VERSION,
        CHUNK_STORE_NAME,
        METADATA_STORE_NAME,
        COMPLETED_STORE_NAME,
        SENDER_METADATA_STORE
    };
}

// Also expose as global for browser use
if (typeof window !== 'undefined') {
    window.P2PChunkStorage = {
        openDatabase,
        saveChunk,
        getChunk,
        getReceivedChunks,
        getMetadata,
        saveMetadata,
        getAllFileIds,
        clearFile,
        reconstructFile,
        getFileId,
        getTotalChunks,
        markFileComplete,
        getCompletedFile,
        getAllCompletedFiles,
        removeCompletedFile,
        // Batch chunk functions
        bufferChunk,
        flushChunkBuffer,
        flushAllChunkBuffers,
        getBufferedChunkCount,
        // OPFS functions
        isOPFSAvailable,
        storeFileInOPFS,
        getFileFromOPFS,
        fileExistsInOPFS,
        deleteFileFromOPFS,
        readOPFSChunk,
        getOPFSFileAsFile,
        clearAllOPFSFiles,
        // Sender metadata functions
        saveSenderFileMetadata,
        getSenderFileMetadata,
        getAllSenderFileMetadata,
        deleteSenderFileMetadata,
        clearAllSenderMetadata,
        cleanupSenderFiles,
        restoreSenderFiles,
        CHUNK_SIZE,
        DB_NAME,
        DB_VERSION,
        CHUNK_STORE_NAME,
        METADATA_STORE_NAME,
        COMPLETED_STORE_NAME,
        SENDER_METADATA_STORE
    };
}
