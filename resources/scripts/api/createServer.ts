import http from '@/api/http';

interface CreateServerData {
    name: string;
    description?: string;
    memory: number;
    cpu: number;
    disk: number;
    productId: number;
}

export default async (data: CreateServerData): Promise<any> => {
    // For now, this is a placeholder that simulates server creation
    // In a real implementation, this would call the appropriate backend API
    return new Promise((resolve, reject) => {
        // Simulate API call delay
        setTimeout(() => {
            // Mock successful response
            resolve({
                id: Math.random().toString(36).substr(2, 9),
                status: 'creating',
                ...data,
            });
        }, 1000);
    });
}; 