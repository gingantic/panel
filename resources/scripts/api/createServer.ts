import http from '@/api/http';

interface CreateServerData {
    name: string;
    description?: string;
    productId: number; // Backend expects product_id
    eggId?: number | null; // Backend expects egg_id, optional
}

export default async (data: CreateServerData): Promise<any> => {
    const payload = {
        name: data.name,
        description: data.description,
        product_id: data.productId,
        egg_id: data.eggId ?? undefined,
    };

    const response = await http.post('/api/client/servers/new', payload);
    return response.data;
}; 