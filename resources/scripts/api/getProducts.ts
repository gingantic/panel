import http from '@/api/http';
import { FractalResponseData, getPaginationSet, PaginatedResult } from '@/api/http';

export interface Product {
    id: number;
    name: string;
    description: string | null;
    cpu: number;
    memory: number;
    disk: number;
    swap: number;
    credits: number;
    maxPerUser: number;
    eggs: { id: number; name: string }[];
}

export const rawDataToProduct = ({ attributes: data }: FractalResponseData): Product => ({
    id: data.id,
    name: data.name,
    description: data.description,
    cpu: data.cpu,
    memory: data.memory,
    disk: data.disk,
    swap: data.swap,
    credits: data.credits,
    maxPerUser: data.max_per_user,
    eggs: data.eggs || [],
});

export default (): Promise<Product[]> => {
    return new Promise((resolve, reject) => {
        http.get('/api/client/products')
            .then(({ data }) => {
                resolve((data.data || []).map((item: any) => rawDataToProduct(item)));
            })
            .catch(reject);
    });
}; 