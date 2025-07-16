import useSWR, { ConfigInterface, responseInterface } from 'swr';
import { AxiosError } from 'axios';
import http, { PaginatedResult, QueryBuilderParams, withQueryBuilderParams } from '@/api/http';
import { toPaginatedSet } from '@definitions/helpers';
import useFilteredObject from '@/plugins/useFilteredObject';
import { useUserSWRKey } from '@/plugins/useSWRKey';

export interface Transaction {
    id: number;
    amount: number;
    type: 'credit' | 'debit';
    description: string | null;
    status: 'pending' | 'completed' | 'failed';
    balanceBefore: number | null;
    balanceAfter: number | null;
    processedAt: Date | null;
    createdAt: Date;
}

export type TransactionFilters = QueryBuilderParams<'type' | 'status', 'created_at' | 'amount'>;

const rawDataToTransaction = (data: any): Transaction => ({
    id: data.id,
    amount: data.amount,
    type: data.type,
    description: data.description,
    status: data.status,
    balanceBefore: data.balance_before,
    balanceAfter: data.balance_after,
    processedAt: data.processed_at ? new Date(data.processed_at) : null,
    createdAt: new Date(data.created_at),
});

const useTransactions = (
    filters?: TransactionFilters,
    config?: ConfigInterface<PaginatedResult<Transaction>, AxiosError>
): responseInterface<PaginatedResult<Transaction>, AxiosError> => {
    const key = useUserSWRKey(['account', 'transactions', JSON.stringify(useFilteredObject(filters || {}))]);

    return useSWR<PaginatedResult<Transaction>>(
        key,
        async () => {
            const { data } = await http.get('/api/client/account/transactions', {
                params: withQueryBuilderParams(filters),
            });

            return toPaginatedSet(data, (datum) => rawDataToTransaction(datum.attributes));
        },
        { revalidateOnMount: false, ...(config || {}) }
    );
};

export { useTransactions, rawDataToTransaction }; 